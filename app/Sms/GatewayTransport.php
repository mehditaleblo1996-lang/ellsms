<?php
/**
 * ELLSMS — the gateway transport: builds a provider request from a compiled connector, executes it,
 * and normalizes the answer (docs/sms-gateway-connectors.md §Transport).
 */
declare(strict_types=1);

function gateway_build_request(array $connector, string $connectorKind, array $context, ?int $routeId, ?int $operatorId): array {
    $section = $connectorKind === 'status' ? $connector['status'] : $connector['send'];
    $merged = gateway_applicable_parameters($connector, $connectorKind, $routeId, $operatorId);
    $headers = [];
    $query = [];
    $body = [];
    $preview = ['headers' => [], 'query' => [], 'body' => []];
    foreach ($merged as $parameter) {
        $value = gateway_parameter_resolve($parameter, $context);
        $shown = $parameter['is_secret'] ? gateway_mask_secret((string)$value) : gateway_preview_value($value);
        switch ($parameter['location']) {
            case 'header':
                $headers[$parameter['key']] = (string)$value;
                $preview['headers'][$parameter['key']] = $shown;
                break;
            case 'query':
                $query[$parameter['key']] = $value;
                $preview['query'][$parameter['key']] = $shown;
                break;
            default:
                $body[$parameter['key']] = $value;
                $preview['body'][$parameter['key']] = $shown;
                break;
        }
    }
    $encodedBody = null;
    $method = $section['method'];
    if ($method !== 'GET' && $body !== []) {
        $encodedBody = $section['content_type'] === 'application/json'
            ? gateway_json_encode_body($body)
            : http_build_query(gateway_form_values($body));
    }
    $path = (string)(parse_url($section['endpoint'], PHP_URL_PATH) ?: '/');
    $auth = gateway_auth_apply($section['auth'] ?? ['type' => 'none'], $method, $path, (string)$encodedBody, (string)($context['request_id'] ?? ''));
    $secretHeaders = gateway_auth_secret_headers($section['auth'] ?? ['type' => 'none']);
    foreach ($auth['headers'] as $name => $value) {
        $headers[$name] = $value;
        $preview['headers'][$name] = in_array($name, $secretHeaders, true) ? gateway_mask_secret($value) : $value;
    }
    foreach ($auth['query'] as $name => $value) {
        $query[$name] = $value;
        $preview['query'][$name] = gateway_mask_secret((string)$value);
    }
    $url = $section['endpoint'];
    if ($query !== []) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    $headerLines = ['Content-Type: ' . $section['content_type']];
    foreach ($headers as $name => $value) $headerLines[] = $name . ': ' . $value;
    return [
        'url'=>$url,'method'=>$method,'headers'=>$headerLines,'body'=>$encodedBody,'content_type'=>$section['content_type'],
        'preview'=>['endpoint'=>$section['endpoint'],'method'=>$method,'headers'=>$preview['headers'] + ['Content-Type'=>$section['content_type']],'query'=>$preview['query'],'body'=>$preview['body']],
    ];
}

function gateway_preview_value(mixed $value): mixed {
    if ($value instanceof GatewayJsonNumber) return $value->decimal;
    if (is_array($value)) return array_map('gateway_preview_value', $value);
    return $value;
}

function gateway_form_values(array $body): array {
    $out=[];
    foreach ($body as $key=>$value) {
        $out[$key] = $value instanceof GatewayJsonNumber ? $value->decimal : (is_array($value) ? array_map(static fn($v)=>$v instanceof GatewayJsonNumber ? $v->decimal : $v, $value) : $value);
    }
    return $out;
}

function gateway_applicable_parameters(array $connector, string $connectorKind, ?int $routeId, ?int $operatorId): array {
    $cacheKey=$connector['gateway_id'].':'.$connector['config_version'].':'.$connectorKind.':'.($routeId??'-').':'.($operatorId??'-');
    if (isset($GLOBALS['__gateway_param_sets'][$cacheKey])) return $GLOBALS['__gateway_param_sets'][$cacheKey]['parameters'];
    $section=$connectorKind==='status'?$connector['status']:$connector['send'];
    $parameters=$section['parameters'];
    $merged=gateway_parameters_merge($parameters['gateway']??[], $routeId!==null?($parameters['route'][$routeId]??[]):[], $operatorId!==null?($parameters['operator'][$operatorId]??[]):[]);
    $GLOBALS['__gateway_param_sets'][$cacheKey]=['parameters'=>$merged,'signature'=>gateway_parameter_set_signature($merged)];
    return $merged;
}

function gateway_parameter_signature(array $connector, string $connectorKind, ?int $routeId, ?int $operatorId): array {
    $cacheKey=$connector['gateway_id'].':'.$connector['config_version'].':'.$connectorKind.':'.($routeId??'-').':'.($operatorId??'-');
    if (!isset($GLOBALS['__gateway_param_sets'][$cacheKey])) gateway_applicable_parameters($connector,$connectorKind,$routeId,$operatorId);
    return $GLOBALS['__gateway_param_sets'][$cacheKey]['signature'];
}

function gateway_send_context(array $input): array {
    $recipients=is_array($input['recipients']??null)?array_values(array_map('strval',$input['recipients'])):[];
    $sender=(string)($input['sender']??''); $message=(string)($input['message']??''); $count=count($recipients);
    $perRecipientMessages=is_array($input['messages']??null)?$input['messages']:null;
    $messagesArray=$perRecipientMessages!==null?array_map(static fn(string $d):string=>(string)($perRecipientMessages[$d]??$message),$recipients):($count>0?array_fill(0,$count,$message):[]);
    $perRecipientIdempotencyKeys=is_array($input['idempotency_keys']??null)?$input['idempotency_keys']:null;
    $idempotencyKeysArray=$perRecipientIdempotencyKeys!==null?array_map(static fn(string $d):string=>(string)($perRecipientIdempotencyKeys[$d]??''),$recipients):[];
    return [
        'sender'=>$sender,'recipient'=>(string)($input['recipient']??($recipients[0]??'')),'recipients'=>implode(',',$recipients),'recipients_array'=>$recipients,
        'senders_array'=>$count>0?array_fill(0,$count,$sender):[],'messages_array'=>$messagesArray,'idempotency_keys_array'=>$idempotencyKeysArray,
        'message'=>$message,'message_type'=>(string)($input['message_type']??''),'request_id'=>(string)($input['request_id']??Logger::currentRequestId()),
        'organization_id'=>(string)($input['organization_id']??''),'operator_code'=>(string)($input['operator_code']??''),'route_code'=>(string)($input['route_code']??''),
        'gateway_code'=>(string)($input['gateway_code']??''),'sender_user_id'=>(string)($input['sender_user_id']??''),'timestamp'=>(string)time(),
    ];
}

function gateway_status_context(array $input): array {
    $ids=$input['provider_message_ids']??[]; if(!is_array($ids))$ids=[]; if($ids===[]&&($input['provider_message_id']??'')!=='')$ids=[(string)$input['provider_message_id']];
    return ['provider_message_id'=>(string)($input['provider_message_id']??($ids[0]??'')),'provider_message_ids'=>implode(',',array_map('strval',$ids)),'request_id'=>(string)($input['request_id']??Logger::currentRequestId()),'sender'=>(string)($input['sender']??''),'recipient'=>(string)($input['recipient']??''),'operator_code'=>(string)($input['operator_code']??''),'route_code'=>(string)($input['route_code']??''),'gateway_code'=>(string)($input['gateway_code']??''),'timestamp'=>(string)time()];
}

function gateway_execute(array $connector, string $connectorKind, array $request): array {
    $section=$connectorKind==='status'?$connector['status']:$connector['send']; $requestId=Logger::currentRequestId();
    $endpointCheck=gateway_endpoint_allowed($request['url']);
    if(!$endpointCheck['ok']){Logger::error('gateway.endpoint_rejected',['gateway_id'=>$connector['gateway_id'],'reason'=>$endpointCheck['reason']]);return ['ok'=>false,'http'=>0,'data'=>null,'error'=>$endpointCheck['reason'],'error_class'=>BackendError::PERMANENT,'request_id'=>$requestId,'raw'=>''];}
    $startedAt=microtime(true); $ch=curl_init($request['url']);
    $options=[CURLOPT_CUSTOMREQUEST=>$request['method'],CURLOPT_HTTPHEADER=>$request['headers'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_CONNECTTIMEOUT_MS=>$section['connect_timeout_ms'],CURLOPT_TIMEOUT_MS=>$section['request_timeout_ms'],CURLOPT_SSL_VERIFYPEER=>$section['tls_verify'],CURLOPT_SSL_VERIFYHOST=>$section['tls_verify']?2:0,CURLOPT_FOLLOWLOCATION=>false];
    if($endpointCheck['resolve']!==[])$options[CURLOPT_RESOLVE]=$endpointCheck['resolve']; if($request['body']!==null)$options[CURLOPT_POSTFIELDS]=$request['body']; curl_setopt_array($ch,$options);
    $raw=curl_exec($ch); $curlErrno=curl_errno($ch); $curlError=curl_error($ch); $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch); $elapsedMs=(int)round((microtime(true)-$startedAt)*1000);
    $metricTags=['gateway'=>$connector['gateway_code'],'connector'=>$connectorKind];
    if($raw===false){$errorClass=$curlErrno===CURLE_OPERATION_TIMEDOUT?BackendError::TIMEOUT:BackendError::UNAVAILABLE;Logger::error('gateway.request_failed',['gateway_id'=>$connector['gateway_id'],'config_version'=>$connector['config_version'],'connector'=>$connectorKind,'curl_errno'=>$curlErrno,'error_class'=>$errorClass,'elapsed_ms'=>$elapsedMs,'request_id'=>$requestId]);Metrics::increment('gateway_send_failure',1,$metricTags+['error_class'=>$errorClass]);return ['ok'=>false,'http'=>0,'data'=>null,'error'=>$curlError?:'connection failed','error_class'=>$errorClass,'request_id'=>$requestId,'raw'=>''];}
    $decoded=json_decode((string)$raw,true,512,JSON_BIGINT_AS_STRING); $bodyIsJson=json_last_error()===JSON_ERROR_NONE; $success=gateway_success_rule_evaluate($section['success'],$http,$decoded,$bodyIsJson);
    Logger::info('gateway.request_completed',['gateway_id'=>$connector['gateway_id'],'config_version'=>$connector['config_version'],'connector'=>$connectorKind,'http'=>$http,'success'=>$success,'elapsed_ms'=>$elapsedMs,'request_id'=>$requestId]);
    Metrics::timing('gateway_request',$elapsedMs,$metricTags+['result'=>$success?'success':'failure']); Metrics::increment($connectorKind==='status'?'gateway_status_poll_total':'gateway_send_total',1,$metricTags);
    if($success)return ['ok'=>true,'http'=>$http,'data'=>$decoded,'error'=>null,'error_class'=>null,'request_id'=>$requestId,'raw'=>(string)$raw];
    $errorClass=gateway_classify_failure($section,$http,$decoded,$bodyIsJson); Metrics::increment($connectorKind==='status'?'gateway_status_poll_failure':'gateway_send_failure',1,$metricTags+['error_class'=>$errorClass]);
    return ['ok'=>false,'http'=>$http,'data'=>$decoded,'error'=>mb_strimwidth((string)$raw,0,1000,'…'),'error_class'=>$errorClass,'request_id'=>$requestId,'raw'=>(string)$raw];
}

function gateway_classify_failure(array $section,int $http,mixed $decoded,bool $bodyIsJson):string{
    $errorMap=$section['errors']??[]; if($errorMap!==[]){$codePath=$section['response']['error_code']??[];$providerCode=$codePath===[]?null:gateway_path_extract($codePath,$decoded);if($providerCode!==null&&isset($errorMap[(string)$providerCode]))return $errorMap[(string)$providerCode];}
    if($http>=200&&$http<300)return $bodyIsJson?BackendError::REJECTED:BackendError::INVALID_RESPONSE;
    return match(true){$http===401,$http===403=>BackendError::UNAUTHORIZED,$http===409=>BackendError::CONFLICT,$http===429=>BackendError::UNAVAILABLE,$http===400,$http===404,$http===422=>BackendError::REJECTED,$http>=500=>BackendError::UNAVAILABLE,default=>BackendError::PERMANENT};
}

function gateway_extract_message_id(array $section,mixed $decoded):?string{$path=$section['response']['provider_message_id']??[];if($path===[])return null;$value=gateway_path_extract($path,$decoded);return is_scalar($value)?(string)$value:null;}
function gateway_extract_batch_result(array $section,mixed $decoded):array{$batch=$section['batch']??null;if($batch===null)return ['sent'=>[],'message_ids'=>[]];$rows=$batch['rows_path']===[]?$decoded:gateway_path_extract($batch['rows_path'],$decoded);if(!is_array($rows))return ['sent'=>[],'message_ids'=>[]];$sent=[];$messageIds=[];foreach($rows as $row){if(!is_array($row))continue;$status=(string)($row[$batch['status_key']]??'');$destination=(string)($row[$batch['destination_key']]??'');if($destination===''||!in_array($status,$batch['success_values'],true))continue;$sent[]=$destination;if($batch['message_id_key']!==''&&isset($row[$batch['message_id_key']]))$messageIds[$destination]=(string)$row[$batch['message_id_key']];}return ['sent'=>$sent,'message_ids'=>$messageIds];}

function gateway_send(array $connector,array $input,?int $routeId,?int $operatorId=null):array{
    $destinations=array_values(array_map('strval',$input['recipients']??[])); if($destinations===[])return gateway_send_failure('no destinations',BackendError::REJECTED);
    $input['gateway_code']=$connector['gateway_code']; $perMessage=$connector['send_mode']!=='batch'; $groups=[];$unsupported=[];$resolvedOperators=[];
    foreach($destinations as $destination){$operator=$operatorId!==null?['operator_id'=>$operatorId,'operator_code'=>(string)($connector['operators'][$operatorId]??'')]:gateway_resolve_recipient_operator($destination);if(!gateway_supports_operator($connector,$operator['operator_id'])){$unsupported[]=$destination;continue;}$signature=gateway_parameter_signature($connector,'send',$routeId,$operator['operator_id']);$groupKey=implode('|',[$connector['gateway_id'],$connector['config_version'],$routeId??'-',$signature['signature'],(string)($input['sender']??''),(string)($input['message_type']??''),($perMessage||$signature['per_recipient'])?$destination:'']);$groups[$groupKey]??=['operator'=>$operator,'destinations'=>[]];$groups[$groupKey]['destinations'][]=$destination;$resolvedOperators[$destination]=$operator['operator_id'];}
    if($groups===[])return gateway_send_failure('gateway does not carry this operator',BackendError::REJECTED);
    $sent=[];$messageIds=[];$lastError=null;$lastClass=null;$lastHttp=0;$retryable=false;
    foreach($groups as $group){$groupDestinations=$group['destinations'];$operator=$group['operator'];$context=gateway_send_context(array_merge($input,['recipients'=>$groupDestinations,'recipient'=>$groupDestinations[0],'operator_code'=>$operator['operator_code']]));$request=gateway_build_request($connector,'send',$context,$routeId,$operator['operator_id']);$response=gateway_execute($connector,'send',$request);if(!$response['ok']){$lastError=$response['error'];$lastClass=$response['error_class'];$lastHttp=$response['http'];$retryable=$retryable||BackendError::isRetryable((string)$response['error_class']);continue;}$lastHttp=$response['http'];[$groupSent,$groupIds]=gateway_read_send_response($connector,$response,$groupDestinations);foreach($groupSent as $destination)$sent[]=$destination;foreach($groupIds as $destination=>$messageId)$messageIds[$destination]=$messageId;}
    if($unsupported!==[]){Logger::warning('gateway.send.operator_not_carried',['gateway_id'=>$connector['gateway_id'],'destination_count'=>count($unsupported)]);if($sent===[]&&$lastClass===null){$lastError='gateway does not carry this operator';$lastClass=BackendError::REJECTED;}}
    if($sent===[]&&$lastClass===null){$lastError='gateway rejected every destination';$lastClass=BackendError::REJECTED;}
    return ['ok'=>$sent!==[],'sent'=>$sent,'message_ids'=>$messageIds,'error'=>$sent===[]?$lastError:null,'error_class'=>$sent===[]?$lastClass:null,'http'=>$lastHttp,'retryable'=>$sent===[]?$retryable:false,'groups'=>count($groups),'operators'=>$resolvedOperators];
}

function gateway_send_failure(string $error,string $errorClass):array{return ['ok'=>false,'sent'=>[],'message_ids'=>[],'error'=>$error,'error_class'=>$errorClass,'http'=>0,'retryable'=>false,'groups'=>0,'operators'=>[]];}
function gateway_resolve_recipient_operator(string $destination):array{$normalized=sms_pricing_normalize_prefix($destination)??'';$operator=sms_resolve_operator($normalized);return ['operator_id'=>$operator['operator_id']!==null?(int)$operator['operator_id']:null,'operator_code'=>(string)$operator['operator_code']];}

function gateway_read_send_response(array $connector,array $response,array $groupDestinations):array{
    if($connector['send_mode']==='batch'&&$connector['send']['batch']!==null){
        $batch=$connector['send']['batch'];
        if($batch['correlation_mode']==='position'){
            $raw=trim((string)($response['raw']??''));$http=(int)($response['http']??0);
            if(!empty($response['ok'])&&$http>=200&&$http<300&&$raw===''){
                Logger::warning('gateway.correlation.empty_success_without_provider_ids',['destinations'=>count($groupDestinations),'http'=>$http]);
                Metrics::increment('gateway.correlation_empty_success',1,['mode'=>'position']);
                return [$groupDestinations,[]];
            }
            return gateway_extract_positional_result($connector['send'],$response['data'],$groupDestinations);
        }
        $batch=gateway_extract_batch_result($connector['send'],$response['data']);$accepted=array_values(array_intersect($batch['sent'],$groupDestinations));$ids=[];foreach($accepted as $destination)if(isset($batch['message_ids'][$destination]))$ids[$destination]=$batch['message_ids'][$destination];return [$accepted,$ids];
    }
    $messageId=gateway_extract_message_id($connector['send'],$response['data']);$ids=$messageId===null?[]:array_fill_keys($groupDestinations,$messageId);return [$groupDestinations,$ids];
}

function gateway_extract_positional_result(array $section,mixed $decoded,array $groupDestinations):array{$batch=$section['batch']??null;if($batch===null||$batch['provider_ids_path']===[])return [[],[]];$ids=gateway_path_extract($batch['provider_ids_path'],$decoded);if(!is_array($ids)){Logger::warning('gateway.correlation.positional_not_array',['destinations'=>count($groupDestinations)]);return [[],[]];}if(count($ids)!==count($groupDestinations)){Logger::warning('gateway.correlation.positional_count_mismatch',['expected'=>count($groupDestinations),'actual'=>count($ids)]);Metrics::increment('gateway.correlation_failure',1,['reason'=>'count_mismatch']);return [[],[]];}$messageIds=[];foreach($groupDestinations as $index=>$destination){$id=$ids[$index]??null;if(!is_scalar($id)||(string)$id===''){Logger::warning('gateway.correlation.positional_empty_id',['index'=>$index]);Metrics::increment('gateway.correlation_failure',1,['reason'=>'empty_id']);return [[],[]];}$messageIds[$destination]=(string)$id;}return [$groupDestinations,$messageIds];}

function gateway_transport_enabled():bool{return (string)env('SMS_GATEWAY_TRANSPORT','0')==='1';}
function gateway_connector_capability_for_sender(string $originator,?string $messageType):array{if(!gateway_transport_enabled())return ['ok'=>false,'per_recipient_content'=>false];$route=sms_pricing_route_for_sender($originator,sms_pricing_normalize_message_type($messageType));$resolved=gateway_for_route($route);if(!$resolved['ok'])return ['ok'=>false,'per_recipient_content'=>false];return ['ok'=>true,'per_recipient_content'=>gateway_connector_supports_per_recipient_content($resolved['connector'])];}
function gateway_send_for_dispatch(array $user,string $originator,array $destinations,string $content,?string $messageType=null,?array $perDestinationContent=null,?array $perDestinationIdempotencyKeys=null):?array{if(!gateway_transport_enabled())return null;$singleDestination=count($destinations)===1?(string)reset($destinations):null;$route=sms_pricing_route_for_sender($originator,sms_pricing_normalize_message_type($messageType),$singleDestination);$resolved=gateway_for_route($route);if(!$resolved['ok']){Logger::warning('gateway.dispatch.falling_back_to_legacy',['reason'=>$resolved['reason'],'route_id'=>$route['route_id']??null,'user_id'=>$user['id']??null]);Metrics::increment('gateway_dispatch_fallback',1,['reason'=>$resolved['reason']]);return null;}$connector=$resolved['connector'];$result=gateway_send($connector,['sender'=>$originator,'recipients'=>array_values(array_map('strval',$destinations)),'message'=>$content,'messages'=>$perDestinationContent,'idempotency_keys'=>$perDestinationIdempotencyKeys,'message_type'=>sms_pricing_normalize_message_type($messageType),'sender_user_id'=>(int)($user['id']??0),'organization_id'=>$user['organization_id']??'','route_code'=>(string)($route['route_code']??'')],isset($route['route_id'])?(int)$route['route_id']:null);$result['gateway_id']=$connector['gateway_id'];$result['gateway_config_version']=$connector['config_version'];$result['route_id']=isset($route['route_id'])?(int)$route['route_id']:null;return $result;}

function gateway_endpoint_allowed(string $url):array{
    $refuse=static fn(string $reason):array=>['ok'=>false,'reason'=>$reason,'resolve'=>[],'addresses'=>[]];$parts=parse_url($url);if($parts===false||empty($parts['host']))return $refuse('endpoint_invalid');$scheme=strtolower((string)($parts['scheme']??''));if(!in_array($scheme,['http','https'],true))return $refuse('endpoint_scheme_not_allowed');$host=(string)$parts['host'];$port=isset($parts['port'])?(int)$parts['port']:($scheme==='https'?443:80);$production=app_env()==='production';if($production&&$scheme!=='https')return $refuse('endpoint_requires_https');foreach(gateway_internal_host_allowlist() as $allowed)if(strcasecmp($host,$allowed)===0)return ['ok'=>true,'reason'=>'','resolve'=>[],'addresses'=>[]];$addresses=gateway_resolve_host($host);if($addresses===[])return $refuse('endpoint_unresolvable');if($production||gateway_enforce_address_rules())foreach($addresses as $address)if(!gateway_address_is_public($address))return $refuse('endpoint_private_address_not_allowed');return ['ok'=>true,'reason'=>'','resolve'=>[$host.':'.$port.':'.$addresses[0]],'addresses'=>$addresses];
}
function gateway_enforce_address_rules():bool{return (string)env('SMS_GATEWAY_ENFORCE_ADDRESS_RULES','0')==='1';}
function gateway_address_is_public(string $address):bool{$binary=@inet_pton($address);if($binary===false)return false;if(strlen($binary)===16&&str_starts_with($binary,"\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff")){$address=inet_ntop(substr($binary,12))?:$address;$binary=@inet_pton($address);if($binary===false)return false;}if(strlen($binary)===16){$first=ord($binary[0]);$second=ord($binary[1]);if($address==='::1'||$binary===str_repeat("\x00",15)."\x01")return false;if($binary===str_repeat("\x00",16))return false;if(($first&0xfe)===0xfc)return false;if($first===0xfe&&($second&0xc0)===0x80)return false;}return (bool)filter_var($address,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);}
function gateway_internal_host_allowlist():array{$raw=(string)env('SMS_GATEWAY_INTERNAL_HOSTS','');return $raw===''?[]:array_values(array_filter(array_map('trim',explode(',',$raw))));}
function gateway_dns_cache_seconds():int{return max(0,(int)(env('SMS_GATEWAY_DNS_CACHE_SECONDS','30')??'30'));}
function gateway_resolve_host(string $host):array{if(filter_var($host,FILTER_VALIDATE_IP))return [$host];$ttl=gateway_dns_cache_seconds();$cached=$GLOBALS['__gateway_dns'][$host]??null;if($ttl>0&&is_array($cached)&&(time()-$cached['at'])<$ttl)return $cached['addresses'];$addresses=[];$records=@dns_get_record($host,DNS_A|DNS_AAAA)?:[];foreach($records as $record){if(!empty($record['ip']))$addresses[]=(string)$record['ip'];if(!empty($record['ipv6']))$addresses[]=(string)$record['ipv6'];}if($addresses===[]){$resolved=gethostbyname($host);if($resolved!==$host&&filter_var($resolved,FILTER_VALIDATE_IP))$addresses[]=$resolved;}$addresses=array_values(array_unique($addresses));$GLOBALS['__gateway_dns'][$host]=['at'=>time(),'addresses'=>$addresses];return $addresses;}
function gateway_dns_cache_reset():void{$GLOBALS['__gateway_dns']=[];}

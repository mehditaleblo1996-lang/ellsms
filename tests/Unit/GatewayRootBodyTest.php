<?php

declare(strict_types=1);

namespace Tests\Unit;

use GatewayConfigException;
use PHPUnit\Framework\TestCase;

/**
 * The root-body parameter (`__root__`): a request whose whole JSON body is one value — PishgamRayan's
 * `StatusWithTime` takes a bare `[id,...]` array. Pure functions, no database.
 */
final class GatewayRootBodyTest extends TestCase
{
    private function parameter(string $key, string $location, string $type, string $value, string $dataType): array {
        return gateway_parameter_compile(
            ['param_key' => $key, 'location' => $location, 'value_type' => $type, 'value' => $value, 'data_type' => $dataType],
            'status',
            []
        );
    }

    private function scopes(array ...$parameters): array {
        return ['gateway' => $parameters, 'route' => [], 'operator' => []];
    }

    private function connector(array $parameters, string $method = 'POST', string $contentType = 'application/json'): array {
        return ['gateway_id' => 990001, 'config_version' => 1, 'status' => [
            'endpoint' => 'https://api.example.test/StatusWithTime', 'method' => $method, 'content_type' => $contentType,
            'auth' => ['type' => 'none'], 'parameters' => $parameters,
        ]];
    }

    public function testRootBodyIsEmittedAsABareArrayWithLongIdsIntact(): void {
        $scopes = $this->scopes(
            $this->parameter('Authorization', 'header', 'static', 'TEST', 'string'),
            $this->parameter(GATEWAY_ROOT_BODY_KEY, 'body', 'variable', 'provider_message_ids', 'integer_list')
        );
        gateway_root_body_validate($scopes, 'POST', 'application/json');

        $context = gateway_status_context(['provider_message_ids' => ['7310136179845801812', '42676161']]);
        $request = gateway_build_request($this->connector($scopes), 'status', $context, null, null);

        $this->assertSame('[7310136179845801812,42676161]', $request['body']);
        $this->assertContains('Authorization: TEST', $request['headers']);
    }

    public function testRootBodyMustBeTheOnlyBodyParameter(): void {
        $scopes = $this->scopes(
            $this->parameter(GATEWAY_ROOT_BODY_KEY, 'body', 'variable', 'provider_message_ids', 'integer_list'),
            $this->parameter('extra', 'body', 'static', '1', 'string')
        );
        $this->expectException(GatewayConfigException::class);
        gateway_root_body_validate($scopes, 'POST', 'application/json');
    }

    public function testRootBodyNeedsAJsonBodyCarryingMethod(): void {
        $scopes = $this->scopes($this->parameter(GATEWAY_ROOT_BODY_KEY, 'body', 'variable', 'provider_message_ids', 'integer_list'));
        foreach ([['GET', 'application/json'], ['POST', 'application/x-www-form-urlencoded']] as [$method, $contentType]) {
            try {
                gateway_root_body_validate($scopes, $method, $contentType);
                $this->fail("$method $contentType must be refused");
            } catch (GatewayConfigException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAConnectorWithoutARootBodyIsUnaffected(): void {
        $scopes = $this->scopes($this->parameter('username', 'body', 'static', 'u', 'string'));
        gateway_root_body_validate($scopes, 'GET', 'application/x-www-form-urlencoded');
        $this->addToAssertionCount(1);
    }
}

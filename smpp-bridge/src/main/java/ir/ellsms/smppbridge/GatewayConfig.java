package ir.ellsms.smppbridge;

/**
 * One SMPP gateway as configured on the ELLSMS panel (ellsms_sms_gateways + ellsms_sms_gateway_smpp_connectors).
 * {@link #signature()} changes whenever anything that affects the sessions changes, so the manager can
 * rebuild exactly the gateways whose configuration moved.
 */
public record GatewayConfig(
    int gatewayId, String code, int configVersion, boolean sendEnabled,
    String host, int port, boolean useTls, String systemId, String password, String systemType,
    String interfaceVersion, String bindMode, int sessionCount, int tps, int windowSize,
    int enquireLinkS, int reconnectDelayS, int submitTimeoutMs,
    int sourceTon, int sourceNpi, int destTon, int destNpi,
    String dataCoding, String longMessage, int registeredDelivery, int validityMinutes,
    String destinationFormat, boolean receiveEnabled, String passwordError
) {
    public String signature() {
        return String.join("|", String.valueOf(configVersion), host, String.valueOf(port), String.valueOf(useTls),
            systemId, String.valueOf(password == null ? 0 : password.hashCode()), systemType, interfaceVersion, bindMode,
            String.valueOf(sessionCount), String.valueOf(windowSize), String.valueOf(enquireLinkS),
            String.valueOf(reconnectDelayS), String.valueOf(submitTimeoutMs), String.valueOf(receiveEnabled));
    }
}

# #46 — ELLSMS SMPP bridge (docs/smpp-gateway.md). Two stages: Maven builds one shaded jar, then a
# slim JRE runs it as an unprivileged user. Tests need a database and run in CI / locally instead
# (see BridgeEndToEndTest), so the image build skips them.
FROM maven:3.9-eclipse-temurin-21 AS build
WORKDIR /src
COPY pom.xml .
RUN mvn -q -B dependency:go-offline
COPY src ./src
RUN mvn -q -B -DskipTests package

FROM eclipse-temurin:21-jre
RUN useradd --system --uid 10001 bridge
COPY --from=build /src/target/smpp-bridge.jar /opt/smpp-bridge/smpp-bridge.jar
USER bridge
EXPOSE 8090
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD ["bash", "-c", "exec 3<>/dev/tcp/127.0.0.1/${SMPP_BRIDGE_PORT:-8090} || exit 1"]
ENTRYPOINT ["java", "-XX:MaxRAMPercentage=75", "-jar", "/opt/smpp-bridge/smpp-bridge.jar"]

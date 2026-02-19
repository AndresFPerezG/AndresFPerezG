<?php

declare(strict_types=1);

namespace Drupal\powerbi_public_embed\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resuelve configuración de embed para reportes públicos permitidos.
 */
final class PowerBiEmbedService {

  private const SETTINGS_CONFIG = 'powerbi_public_embed.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Obtiene la configuración lista para powerbi-client.
   */
  public function getEmbedConfiguration(string $slug): array {
    $report = $this->getAllowedReportBySlug($slug);
    if ($report === NULL) {
      throw new NotFoundHttpException('El reporte solicitado no está permitido.');
    }

    if ($this->isMockMode()) {
      return $this->buildMockResponse($report);
    }

    $cache_id = 'powerbi_public_embed.embed_config.' . hash('sha256', $slug);
    $cached = $this->cache->get($cache_id);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $access_token = $this->getPowerBiAccessToken();
    $embed_token_response = $this->requestEmbedToken($report, $access_token);

    if (empty($embed_token_response['token'])) {
      throw new \RuntimeException('Power BI no devolvió token de embed.');
    }

    $expiration = (string) ($embed_token_response['expiration'] ?? gmdate('c', time() + 600));
    $response = [
      'slug' => $report['slug'],
      'reportId' => $report['report_id'],
      'groupId' => $report['workspace_id'],
      'embedUrl' => $this->buildEmbedUrl($report['workspace_id'], $report['report_id']),
      'accessToken' => (string) $embed_token_response['token'],
      'tokenType' => 'Embed',
      'expiration' => $expiration,
      'settings' => $this->buildClientSettings(),
    ];

    $ttl = $this->resolveEmbedCacheTtl($expiration);
    $this->cache->set($cache_id, $response, time() + $ttl);
    return $response;
  }

  /**
   * Devuelve TRUE cuando el módulo está en modo ejemplo/demostración.
   */
  public function isMockMode(): bool {
    return (bool) $this->getConfigValue('mock_mode', TRUE);
  }

  /**
   * Obtiene reporte permitido por slug desde la allowlist.
   */
  public function getAllowedReportBySlug(string $slug): ?array {
    $raw = trim((string) $this->getConfigValue('allowed_reports_raw', ''));
    if ($raw === '') {
      return NULL;
    }

    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }

      $parts = array_map('trim', explode('|', $line));
      if (count($parts) < 3) {
        continue;
      }

      [$line_slug, $workspace_id, $report_id] = $parts;
      $dataset_id = $parts[3] ?? '';
      if ($line_slug !== $slug) {
        continue;
      }

      if ($workspace_id === '' || $report_id === '') {
        return NULL;
      }

      return [
        'slug' => $line_slug,
        'workspace_id' => $workspace_id,
        'report_id' => $report_id,
        'dataset_id' => $dataset_id,
      ];
    }

    return NULL;
  }

  /**
   * Construye respuesta mock para pruebas sin credenciales.
   */
  private function buildMockResponse(array $report): array {
    $expiration = gmdate('c', time() + 600);
    return [
      'slug' => $report['slug'],
      'reportId' => $report['report_id'],
      'groupId' => $report['workspace_id'],
      'embedUrl' => $this->buildEmbedUrl($report['workspace_id'], $report['report_id']),
      'accessToken' => 'mock_' . hash('sha256', $report['slug'] . ':' . (string) time()),
      'tokenType' => 'Embed',
      'expiration' => $expiration,
      'settings' => $this->buildClientSettings(),
      'isMock' => TRUE,
    ];
  }

  /**
   * Solicita token de acceso contra Entra ID con client credentials.
   */
  private function getPowerBiAccessToken(): string {
    $cache_id = 'powerbi_public_embed.azure_access_token';
    $cached = $this->cache->get($cache_id);
    if ($cached !== FALSE && !empty($cached->data)) {
      return (string) $cached->data;
    }

    $tenant_id = trim((string) $this->getConfigValue('tenant_id', ''));
    $client_id = trim((string) $this->getConfigValue('client_id', ''));
    $client_secret = trim((string) $this->getConfigValue('client_secret', ''));

    if ($tenant_id === '' || $client_id === '' || $client_secret === '') {
      throw new \RuntimeException('Faltan credenciales de Entra ID para solicitar token.');
    }

    $authority_host = rtrim((string) $this->getConfigValue('authority_host', 'https://login.microsoftonline.com'), '/');
    $scope = trim((string) $this->getConfigValue('scope', 'https://analysis.windows.net/powerbi/api/.default'));
    $token_url = sprintf('%s/%s/oauth2/v2.0/token', $authority_host, rawurlencode($tenant_id));

    try {
      $response = $this->httpClient->request('POST', $token_url, [
        'form_params' => [
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'scope' => $scope,
          'grant_type' => 'client_credentials',
        ],
        'timeout' => 10,
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Error solicitando access token de Power BI: @message', [
        '@message' => $exception->getMessage(),
      ]);
      throw new \RuntimeException('No se pudo obtener access token para Power BI.', 0, $exception);
    }

    if (empty($payload['access_token'])) {
      throw new \RuntimeException('Respuesta inválida al solicitar access token.');
    }

    $configured_ttl = (int) $this->getConfigValue('token_cache_ttl', 300);
    $expires_in = (int) ($payload['expires_in'] ?? 3600);
    $ttl = max(60, min($configured_ttl, $expires_in - 120));
    $this->cache->set($cache_id, (string) $payload['access_token'], time() + $ttl);

    return (string) $payload['access_token'];
  }

  /**
   * Solicita embed token de reporte.
   */
  private function requestEmbedToken(array $report, string $access_token): array {
    $api_base = rtrim((string) $this->getConfigValue('api_base_url', 'https://api.powerbi.com'), '/');
    $endpoint = sprintf(
      '%s/v1.0/myorg/groups/%s/reports/%s/GenerateToken',
      $api_base,
      rawurlencode($report['workspace_id']),
      rawurlencode($report['report_id']),
    );

    $payload = [
      'accessLevel' => 'View',
      'allowSaveAs' => FALSE,
    ];

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => 10,
      ]);
      return json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Error solicitando embed token para reporte @slug: @message', [
        '@slug' => $report['slug'],
        '@message' => $exception->getMessage(),
      ]);
      throw new \RuntimeException('No se pudo obtener embed token de Power BI.', 0, $exception);
    }
  }

  /**
   * Configuración mínima recomendada para el cliente.
   */
  private function buildClientSettings(): array {
    $disable_export_data = (bool) $this->getConfigValue('disable_export_data', TRUE);
    return [
      'panes' => [
        'filters' => ['visible' => FALSE, 'expanded' => FALSE],
        'pageNavigation' => ['visible' => FALSE],
      ],
      'bars' => [
        'statusBar' => ['visible' => FALSE],
        'actionBar' => ['visible' => !$disable_export_data],
      ],
    ];
  }

  /**
   * Calcula TTL de cache para el embed token evitando usar tokens expirados.
   */
  private function resolveEmbedCacheTtl(string $expiration): int {
    $configured_ttl = (int) $this->getConfigValue('embed_token_cache_ttl', 240);
    $expiration_ts = strtotime($expiration);
    if ($expiration_ts === FALSE) {
      return max(30, $configured_ttl);
    }

    $ttl = $expiration_ts - time() - 60;
    return max(30, min($configured_ttl, $ttl));
  }

  /**
   * Construye embed URL oficial de Power BI.
   */
  private function buildEmbedUrl(string $workspace_id, string $report_id): string {
    return sprintf(
      'https://app.powerbi.com/reportEmbed?reportId=%s&groupId=%s',
      rawurlencode($report_id),
      rawurlencode($workspace_id),
    );
  }

  /**
   * Lee valor de configuración del módulo.
   */
  private function getConfigValue(string $key, mixed $default = NULL): mixed {
    return $this->configFactory->get(self::SETTINGS_CONFIG)->get($key) ?? $default;
  }

}

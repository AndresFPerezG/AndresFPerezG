<?php

declare(strict_types=1);

namespace Drupal\powerbi_public_embed\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\powerbi_public_embed\Service\PowerBiEmbedService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoint público que expone únicamente configuración permitida de embed.
 */
final class EmbedConfigController extends ControllerBase {

  public function __construct(
    private readonly PowerBiEmbedService $embedService,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('powerbi_public_embed.embed_service'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('logger.channel.powerbi_public_embed'),
    );
  }

  /**
   * Devuelve embed config para el slug solicitado.
   */
  public function getConfig(string $slug, Request $request): JsonResponse {
    if (!$this->isRequestAllowed($request, $slug)) {
      return new JsonResponse(
        ['error' => 'Demasiadas solicitudes. Intenta nuevamente en unos segundos.'],
        429,
        $this->buildNoStoreHeaders(),
      );
    }

    try {
      $config = $this->embedService->getEmbedConfiguration($slug);
      return new JsonResponse($config, 200, $this->buildNoStoreHeaders());
    }
    catch (NotFoundHttpException) {
      return new JsonResponse(['error' => 'Reporte no disponible.'], 404, $this->buildNoStoreHeaders());
    }
    catch (\Throwable $exception) {
      $this->logger->error('Fallo en endpoint embed-config para slug @slug: @message', [
        '@slug' => $slug,
        '@message' => $exception->getMessage(),
      ]);
      return new JsonResponse(
        ['error' => 'No se pudo generar el token de visualización.'],
        500,
        $this->buildNoStoreHeaders(),
      );
    }
  }

  /**
   * Control anti abuso del endpoint por IP + slug.
   */
  private function isRequestAllowed(Request $request, string $slug): bool {
    $config = $this->configFactory->get('powerbi_public_embed.settings');
    $limit = max(1, (int) ($config->get('endpoint_rate_limit') ?? 120));
    $window = max(1, (int) ($config->get('endpoint_rate_window') ?? 60));
    $identifier = ($request->getClientIp() ?? 'unknown') . ':' . $slug;
    $event = 'powerbi_public_embed.embed_config';

    if (!$this->flood->isAllowed($event, $limit, $window, $identifier)) {
      return FALSE;
    }

    $this->flood->register($event, $window, $identifier);
    return TRUE;
  }

  /**
   * Evita cachear respuestas con tokens temporales.
   */
  private function buildNoStoreHeaders(): array {
    return [
      'Cache-Control' => 'no-store, private',
      'Pragma' => 'no-cache',
      'Expires' => '0',
    ];
  }

}

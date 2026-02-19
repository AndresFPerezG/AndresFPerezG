<?php

declare(strict_types=1);

namespace Drupal\powerbi_public_embed\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulario de configuración para integración pública de Power BI.
 */
final class PowerBiPublicEmbedSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'powerbi_public_embed_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['powerbi_public_embed.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('powerbi_public_embed.settings');

    $form['auth'] = [
      '#type' => 'details',
      '#title' => $this->t('Autenticación Entra ID (server-to-server)'),
      '#open' => TRUE,
    ];

    $form['auth']['tenant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tenant ID'),
      '#default_value' => $config->get('tenant_id'),
      '#required' => TRUE,
    ];
    $form['auth']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];
    $form['auth']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client secret'),
      '#description' => $this->t('Deja este campo vacío para conservar el valor actual.'),
      '#required' => FALSE,
    ];
    $form['auth']['authority_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authority host'),
      '#default_value' => $config->get('authority_host') ?: 'https://login.microsoftonline.com',
      '#required' => TRUE,
    ];
    $form['auth']['scope'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Scope'),
      '#default_value' => $config->get('scope') ?: 'https://analysis.windows.net/powerbi/api/.default',
      '#required' => TRUE,
    ];
    $form['auth']['api_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Power BI API base URL'),
      '#default_value' => $config->get('api_base_url') ?: 'https://api.powerbi.com',
      '#required' => TRUE,
    ];

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Controles de seguridad'),
      '#open' => TRUE,
    ];
    $form['security']['endpoint_rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit'),
      '#description' => $this->t('Número máximo de solicitudes por ventana de tiempo para cada IP+slug.'),
      '#default_value' => $config->get('endpoint_rate_limit') ?? 120,
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['security']['endpoint_rate_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Ventana de rate limit (segundos)'),
      '#default_value' => $config->get('endpoint_rate_window') ?? 60,
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['security']['token_cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Access token cache TTL (segundos)'),
      '#default_value' => $config->get('token_cache_ttl') ?? 300,
      '#min' => 60,
      '#required' => TRUE,
    ];
    $form['security']['embed_token_cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Embed token cache TTL (segundos)'),
      '#default_value' => $config->get('embed_token_cache_ttl') ?? 240,
      '#min' => 30,
      '#required' => TRUE,
    ];
    $form['security']['disable_export_data'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Desactivar barra de acciones para reducir opciones de exportación'),
      '#default_value' => (bool) $config->get('disable_export_data'),
    ];
    $form['security']['mock_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mock mode (demo sin credenciales reales)'),
      '#description' => $this->t('Si está activo, el endpoint devuelve un token simulado y no llama a Power BI.'),
      '#default_value' => (bool) $config->get('mock_mode'),
    ];

    $form['reports'] = [
      '#type' => 'details',
      '#title' => $this->t('Allowlist de reportes'),
      '#open' => TRUE,
    ];
    $form['reports']['allowed_reports_raw'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Reportes permitidos'),
      '#description' => $this->t("Un reporte por línea con formato: slug|workspace_id|report_id|dataset_id(opcional)\nEjemplo: economia-nacional|aaaaaaaa-...|bbbbbbbb-...|cccccccc-..."),
      '#default_value' => $config->get('allowed_reports_raw'),
      '#rows' => 8,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $raw = trim((string) $form_state->getValue('allowed_reports_raw'));
    if ($raw === '') {
      $form_state->setErrorByName('allowed_reports_raw', $this->t('Debes definir al menos un reporte permitido.'));
    }

    $lines = preg_split('/\R/', $raw) ?: [];
    foreach ($lines as $index => $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }

      $parts = array_map('trim', explode('|', $line));
      if (count($parts) < 3) {
        $form_state->setErrorByName(
          'allowed_reports_raw',
          $this->t('La línea @line debe tener al menos slug, workspace_id y report_id.', ['@line' => (string) ($index + 1)]),
        );
        break;
      }

      $slug = $parts[0];
      if (!preg_match('/^[a-z0-9\-]+$/', $slug)) {
        $form_state->setErrorByName(
          'allowed_reports_raw',
          $this->t('El slug en la línea @line solo puede contener minúsculas, números y guiones.', ['@line' => (string) ($index + 1)]),
        );
        break;
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('powerbi_public_embed.settings');
    $current_secret = (string) $config->get('client_secret');
    $new_secret = trim((string) $form_state->getValue('client_secret'));

    $config
      ->set('tenant_id', trim((string) $form_state->getValue('tenant_id')))
      ->set('client_id', trim((string) $form_state->getValue('client_id')))
      ->set('client_secret', $new_secret !== '' ? $new_secret : $current_secret)
      ->set('authority_host', trim((string) $form_state->getValue('authority_host')))
      ->set('scope', trim((string) $form_state->getValue('scope')))
      ->set('api_base_url', trim((string) $form_state->getValue('api_base_url')))
      ->set('endpoint_rate_limit', (int) $form_state->getValue('endpoint_rate_limit'))
      ->set('endpoint_rate_window', (int) $form_state->getValue('endpoint_rate_window'))
      ->set('token_cache_ttl', (int) $form_state->getValue('token_cache_ttl'))
      ->set('embed_token_cache_ttl', (int) $form_state->getValue('embed_token_cache_ttl'))
      ->set('disable_export_data', (bool) $form_state->getValue('disable_export_data'))
      ->set('mock_mode', (bool) $form_state->getValue('mock_mode'))
      ->set('allowed_reports_raw', trim((string) $form_state->getValue('allowed_reports_raw')))
      ->save();

    parent::submitForm($form, $form_state);
  }

}

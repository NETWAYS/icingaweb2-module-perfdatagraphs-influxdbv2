<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Forms;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Client\Influx;

use Icinga\Forms\ConfigForm;

use Exception;

/**
 * PerfdataGraphsInfluxDBv2ConfigForm represents the configuration form for the PerfdataGraphs InfluxDBv2 Module.
 */
class PerfdataGraphsInfluxDBv2ConfigForm extends ConfigForm
{
    public function init()
    {
        $this->setName('form_config_perfdatainfluxdbv2');
        $this->setSubmitLabel($this->translate('Save Changes'));
        $this->setValidatePartial(true);
    }

    public function createElements(array $formData)
    {
        $this->addElement('text', 'influx_api_url', [
            'label' => t('InfluxDB API URL'),
            'description' => t('The URL for InfluxDB including the scheme'),
            'required' => true,
            'placeholder' => 'http://localhost:8086',
        ]);

        $this->addElement('text', 'influx_api_org', [
            'label' => t('InfluxDB organization'),
            'description' => t('The organization for the bucket'),
            'required' => true
        ]);

        $this->addElement('text', 'influx_api_bucket', [
            'label' => t('InfluxDB bucket'),
            'description' => t('the bucket for the performance data'),
            'required' => true
        ]);

        $this->addElement('select', 'influx_api_auth_method', [
            'label' => 'API authentication method',
            'description' => 'Authentication method to use for the API',
            'multiOptions' => [
                'none' => t('None'),
                'token' => 'Token',
                'basic' => 'Basic Auth',
            ],
            'class' => 'autosubmit',
            'required' => false,
        ]);

        if (isset($formData['influx_api_auth_method']) && $formData['influx_api_auth_method'] === 'basic') {
            $this->addElement('text', 'influx_api_auth_username', [
                'label' => t('HTTP basic auth username'),
                'description' => t('The user for HTTP basic auth'),
                'required' => true,
            ]);

            $this->addElement('password', 'influx_api_auth_password', [
                'label' => t('HTTP basic auth password'),
                'description' => t('The password for HTTP basic auth'),
                'renderPassword' => true,
                'required' => true,
            ]);
        }

        if (isset($formData['influx_api_auth_method']) && $formData['influx_api_auth_method'] === 'token') {
            $this->addElement('text', 'influx_api_auth_tokentype', [
                'label' => t('Token type for the Authorization header'),
                'description' => t('API Token type for the Authorization header (default: Token)'),
                'value' => 'Token',
            ]);

            $this->addElement('password', 'influx_api_auth_tokenvalue', [
                'label' => t('Token for the Authorization header'),
                'description' => t('API Token for the Authorization header'),
                'renderPassword' => true,
                'required' => true,
            ]);
        }

        $this->addElement('checkbox', 'influx_api_auth_mtls', [
            'label' => t('Use client certificate (mTLS)'),
            'description' => t('Use client certificate (mTLS) for the connection'),
            'class' => 'autosubmit',
        ]);

        if (isset($formData['influx_api_auth_mtls']) && $formData['influx_api_auth_mtls'] === '1') {
            $this->addElement('text', 'influx_api_auth_mtls_cert', [
                'label' => t('mTLS client certificate path'),
                'description' => t('Path to the client certificate'),
                'required' => true,
            ]);
            $this->addElement('text', 'influx_api_auth_mtls_key', [
                'label' => t('mTLS client key path'),
                'description' => t('Path to the client key'),
                'required' => true,
            ]);
            $this->addElement('text', 'influx_api_auth_mtls_ca', [
                'label' => t('mTLS client CA path'),
                'description' => t('Path to the CA. Defaults to system CA'),
                'required' => false,
            ]);
        }

        $this->addElement('number', 'influx_api_timeout', [
            'label' => t('HTTP timeout in seconds'),
            'description' => t('HTTP timeout for the API in seconds. Should be higher than 0'),
            'placeholder' => 10,
        ]);

        $this->addElement('number', 'influx_api_max_data_points', [
            'label' => t('The maximum numbers of datapoints each series returns'),
            'description' => t(' '),
            'description'   => t(
                'The maximum numbers of datapoints each series returns.'
                    . ' If there are more datapoints the module will use the Flux function aggregateWindow to downsample to this number.'
                    . ' You can disable aggregation by setting this to 0.'
            ),
            'required' => false,
            'placeholder' => 10000,
        ]);

        $this->addElement('checkbox', 'influx_api_tls_insecure', [
            'description' => t('Skip the TLS verification'),
            'label' => 'Skip the TLS verification'
        ]);

        $this->addElement(
            'text',
            'influx_writer_host_name_template_tag',
            [
                'label' => t('Host name template tag'),
                'description' => t('The configured tag name for the "host name" in Icinga 2 Influxdb2Writer'),
                'placeholder' => 'hostname',
            ]
        );

        $this->addElement(
            'text',
            'influx_writer_service_name_template_tag',
            [
                'label' => t('Service name template tag'),
                'description' => t('The configured tag name for the "service name" in Icinga 2 Influxdb2Writer'),
                'placeholder' => 'service',
            ]
        );

        $this->addElement(
            'select',
            'influx_writer_measurement_source',
            [
                'label' => t('Measurement source'),
                'description' => t(
                    'Which value is used as the InfluxDB _measurement when building queries.'
                        . ' Must match how measurement is set in the Icinga 2 Influxdb2Writer'
                        . ' (host_template/service_template).'
                ),
                'multiOptions' => [
                    'checkcommand' => t('check_command (Icinga 2 documentation default)'),
                    'hostname'     => t('Host name'),
                    'static'       => t('Static value'),
                ],
                'value' => 'checkcommand',
                'class' => 'autosubmit',
            ]
        );

        if (
            isset($formData['influx_writer_measurement_source'])
            && $formData['influx_writer_measurement_source'] === 'static'
        ) {
            $this->addElement(
                'text',
                'influx_writer_measurement_static_value',
                [
                    'label' => t('Static measurement value'),
                    'description' => t(
                        'Fixed value used for _measurement on every query, e.g. "icinga".'
                            . ' Only used when Measurement source is set to "Static value".'
                    ),
                    'required' => true,
                ]
            );
        }
    }

    public function addSubmitButton()
    {
        parent::addSubmitButton()
            ->getElement('btn_submit')
            ->setDecorators(['ViewHelper']);

        $this->addElement(
            'submit',
            'backend_validation',
            [
                'ignore' => true,
                'label' => $this->translate('Validate Configuration'),
                'data-progress-label' => $this->translate('Validation in Progress'),
                'decorators' => ['ViewHelper']
            ]
        );

        $this->setAttrib('data-progress-element', 'backend-progress');
        $this->addElement(
            'note',
            'backend-progress',
            [
                'decorators' => [
                    'ViewHelper',
                    ['Spinner', ['id' => 'backend-progress']]
                ]
            ]
        );

        $this->addDisplayGroup(
            ['btn_submit', 'backend_validation', 'backend-progress'],
            'submit_validation',
            [
                'decorators' => [
                    'FormElements',
                    ['HtmlTag', ['tag' => 'div', 'class' => 'control-group form-controls']]
                ]
            ]
        );

        return $this;
    }

    public function isValidPartial(array $formData)
    {
        if ($this->getElement('backend_validation')->isChecked() && parent::isValid($formData)) {
            $validation = static::validateFormData($this);
            if ($validation !== null) {
                $this->addElement(
                    'note',
                    'inspection_output',
                    [
                        'order' => 0,
                        'value' => '<strong>' . $this->translate('Validation Log') . "</strong>\n\n"
                            . $validation['output'],
                        'decorators' => [
                            'ViewHelper',
                            ['HtmlTag', ['tag' => 'pre', 'class' => 'log-output']],
                        ]
                    ]
                );

                if (isset($validation['error'])) {
                    $this->warning(sprintf(
                        $this->translate('Failed to successfully validate the configuration: %s'),
                        $validation['error']
                    ));
                    return false;
                }
            }

            $this->info($this->translate('The configuration has been successfully validated.'));
        }

        return true;
    }

    public static function validateFormData($form): array
    {
        $baseURI = $form->getValue('influx_api_url', 'http://localhost:8086');
        $timeout = (int) $form->getValue('influx_api_timeout', 10);
        $org = $form->getValue('influx_api_org', '');
        $bucket = $form->getValue('influx_api_bucket', '');
        // Auth values
        $authMethod = $form->getValue('influx_api_auth_method', 'none');
        $authTokenType = $form->getValue('influx_api_auth_tokentype', 'Token');
        $authTokenValue = $form->getValue('influx_api_auth_tokenvalue', '');
        $authUsername = $form->getValue('influx_api_auth_username', '');
        $authPassword = $form->getValue('influx_api_auth_password', '');
        // mTLS values
        $authMTLS = $form->getValue('influx_api_auth_mtls', false);
        $authMTLSCert = $form->getValue('influx_api_auth_mtls_cert', '');
        $authMTLSKey = $form->getValue('influx_api_auth_mtls_key', '');
        $authMTLSCA = $form->getValue('influx_api_auth_mtls_ca', '');
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $form->getValue('influx_api_tls_insecure', false);
        $maxDataPoints = (int) $form->getValue('influx_api_max_data_points', 10000);
        $hostnameTag = $form->getValue('influx_writer_host_name_template_tag', 'hostname');
        $servicenameTag = $form->getValue('influx_writer_service_name_template_tag', 'service');
        $measurementSource = $form->getValue('influx_writer_measurement_source', 'checkcommand');
        $measurementStaticValue = (string) ($form->getValue('influx_writer_measurement_static_value', ''));

        $auth = [
            'method' => mb_strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
            'mtls' => $authMTLS,
            'mtls_cert' => $authMTLSCert,
            'mtls_key' => $authMTLSKey,
            'mtls_ca' => $authMTLSCA,
        ];

        try {
            $c = new Influx(
                baseURI: $baseURI,
                org: $org,
                bucket: $bucket,
                hostnameTag: $hostnameTag,
                servicenameTag: $servicenameTag,
                measurementSource: $measurementSource,
                measurementStaticValue: $measurementStaticValue,
                timeout: $timeout,
                maxDataPoints: $maxDataPoints,
                tlsVerify: $tlsVerify,
                auth: $auth,
            );
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        $status = $c->status();

        return $status;
    }
}

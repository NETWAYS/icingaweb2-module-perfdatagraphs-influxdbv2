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
            'label' => t('InfluxDB Bucket'),
            'description' => t('the bucket for the performance data'),
            'required' => true
        ]);

        $this->addElement('password', 'influx_api_token', [
            'label' => t('InfluxDB API Token'),
            'description' => t('Token for the authentication'),
            'renderPassword' => true,
        ]);

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
        $token = $form->getValue('influx_api_token', '');
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $form->getValue('influx_api_tls_insecure', false);

        try {
            $c = new Influx($baseURI, $org, $bucket, $token, $timeout, $tlsVerify);
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $e->getMessage(), 'error' => true];
        }

        $status = $c->status();

        return $status;
    }
}

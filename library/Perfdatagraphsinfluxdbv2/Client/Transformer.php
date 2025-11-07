<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Vendor\FluxCsvParser;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use GuzzleHttp\Psr7\Response;

/**
 * Transformer handles all data transformation.
 */
class Transformer
{
    public static function isIncluded($metricname, array $includeMetrics = []): bool
    {
        // All are included if not set
        if (count($includeMetrics) === 0) {
            return true;
        }
        foreach ($includeMetrics as $pattern) {
            if (fnmatch($pattern, $metricname)) {
                return true;
            }
        }
        return false;
    }

    public static function isExcluded($metricname, array $excludeMetrics = []): bool
    {
        // None are exlucded if not set
        if (count($excludeMetrics) === 0) {
            return false;
        }

        foreach ($excludeMetrics as $pattern) {
            if (fnmatch($pattern, $metricname)) {
                return true;
            }
        }
        return false;
    }

    /**
     * transform takes the InfluxDB response and transforms it into the
     * output format we need.
     *
     * @param GuzzleHttp\Psr7\Response $response the data to transform
     * @return PerfdataResponse
     */
    public static function transform(
        Response $response,
        array $includeMetrics = [],
        array $excludeMetrics = [],
    ): PerfdataResponse {
        $pfr = new PerfdataResponse();

        if (empty($response)) {
            Logger::warning('Did not receive data in response');
            return $pfr;
        }

        $stream = new FluxCsvParser($response->getBody(), true);

        foreach ($stream->each() as $record) {
            $metricname = $record['metric'];

            if (!self::isIncluded($metricname, $includeMetrics)) {
                continue;
            }

            if (self::isExcluded($metricname, $excludeMetrics)) {
                continue;
            }

            $unit = '';
            if ($record->getField() === 'unit' && empty($unit)) {
                $unit = $record->getValue();
            }

            // Check if we know this metricname already
            $dataset = $pfr->getDataset($metricname);
            $isNewDataset = false;

            if ($dataset === null) {
                $isNewDataset = true;
                // If not, create a new one
                $dataset = new PerfdataSet($metricname, $unit);
            }

            $dataset->addTimestamp(strtotime($record->getTime()));

            // Check if got a series for value, warning, critical
            $series = $dataset->getSeries();

            // Handle the value column
            $hasValueSeries = array_key_exists('value', $series);

            if ($record->getField() === 'value') {
                if ($hasValueSeries) {
                    $series['value']->addValue($record->getValue());
                } else {
                    $values = new PerfdataSeries('value');
                    $values->addValue($record->getValue());
                    $dataset->addSeries($values);
                }
            }

            // Handle the warning column
            $hasWarningSeries = array_key_exists('warning', $series);

            if ($record->getField() === 'warn') {
                if ($hasWarningSeries) {
                    $series['warning']->addValue($record->getValue());
                } else {
                    $warnings = new PerfdataSeries('warning');
                    $warnings->addValue($record->getValue());
                    $dataset->addSeries($warnings);
                }
            }

            // Handle the warning column
            $hasCriticalSeries = array_key_exists('critical', $series);

            if ($record->getField() === 'crit') {
                if ($hasCriticalSeries) {
                    $series['critical']->addValue($record->getValue());
                } else {
                    $criticals = new PerfdataSeries('critical');
                    $criticals->addValue($record->getValue());
                    $dataset->addSeries($criticals);
                }
            }

            if ($isNewDataset) {
                $pfr->addDataset($dataset);
            }
        }

        return $pfr;
    }
}

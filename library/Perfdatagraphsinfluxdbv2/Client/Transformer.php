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

        $timestamps = [];
        // Create PerfdataSeries and add to PerfdataSet
        $valueseries = [];
        $warningseries = [];
        $criticalseries = [];

        foreach ($stream->each() as $record) {
            $metricname = $record['metric'];

            if (!self::isIncluded($metricname, $includeMetrics)) {
                continue;
            }

            if (self::isExcluded($metricname, $excludeMetrics)) {
                continue;
            }

            if ($record->getField() === 'warn') {
                if (!isset($warningseries[$metricname])) {
                    $warningseries[$metricname] = [];
                }
                $warningseries[$metricname][] = $record->getValue();
            };

            if ($record->getField() === 'crit') {
                if (!isset($criticalseries[$metricname])) {
                    $criticalseries[$metricname] = [];
                }
                $criticalseries[$metricname][] = $record->getValue();
            };

            if ($record->getField() === 'value') {
                if (!isset($valueseries[$metricname])) {
                    $valueseries[$metricname] = [];
                };

                if (!isset($timestamps[$metricname])) {
                    $timestamps[$metricname] = [];
                }
                $ts = strtotime($record->getTime());
                $value = $record->getValue();

                $timestamps[$metricname][] = $ts;
                $valueseries[$metricname][] = isset($value) ? $value : null;
            }
        }

        // Add it to the PerfdataResponse
        foreach (array_keys($valueseries) as $metric) {
            $s = new PerfdataSet($metric);

            $s->setTimestamps($timestamps[$metric]);

            if (array_key_exists($metric, $valueseries)) {
                $values = new PerfdataSeries('value', $valueseries[$metric]);
                $s->addSeries($values);
            }

            if (array_key_exists($metric, $warningseries) && !empty($warningseries)) {
                $warnings = new PerfdataSeries('warning', $warningseries[$metric]);
                $s->addSeries($warnings);
            }

            if (array_key_exists($metric, $criticalseries) && !empty($criticalseries)) {
                $criticals = new PerfdataSeries('critical', $criticalseries[$metric]);
                $s->addSeries($criticals);
            }

            $pfr->addDataset($s);
        }

        return $pfr;
    }
}

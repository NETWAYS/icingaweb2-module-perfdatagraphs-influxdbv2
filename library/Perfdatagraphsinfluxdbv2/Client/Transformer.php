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
        $valueseries = [];
        $warningseries = [];
        $criticalseries = [];
        $units = [];

        foreach ($stream->each() as $record) {
            $metricname = $record['metric'];

            if (!self::isIncluded($metricname, $includeMetrics)) {
                continue;
            }

            if (self::isExcluded($metricname, $excludeMetrics)) {
                continue;
            }

            if (!isset($warningseries[$metricname])) {
                $warningseries[$metricname] = [];
            }

            if (!isset($criticalseries[$metricname])) {
                $criticalseries[$metricname] = [];
            }

            if (!isset($valueseries[$metricname])) {
                $valueseries[$metricname] = [];
            }

            if (!isset($timestamps[$metricname])) {
                $timestamps[$metricname] = [];
            }

            $timestamps[$metricname][] = strtotime($record->getTime());
            $valueseries[$metricname][] = $record['value'];
            $units[$metricname] = $record['unit'] ?? '';
            $warningseries[$metricname][] = $record['warn'] ?? null;
            $criticalseries[$metricname][] = $record['crit'] ?? null;
        }

        // Add it to the PerfdataResponse
        // TODO: We could probably do this in the previous loop
        foreach (array_keys($valueseries) as $metric) {
            $s = new PerfdataSet($metric, $units[$metric] ?? '');

            $s->setTimestamps($timestamps[$metric]);

            if (array_key_exists($metric, $valueseries)) {
                $values = new PerfdataSeries('value', $valueseries[$metric]);
                $s->addSeries($values);
            }

            if (array_key_exists($metric, $warningseries)) {
                if (count(array_filter($warningseries[$metric], fn($v)=> $v !== null)) > 0) {
                    $warnings = new PerfdataSeries('warning', $warningseries[$metric]);
                    $s->addSeries($warnings);
                }
            }

            if (array_key_exists($metric, $criticalseries)) {
                if (count(array_filter($criticalseries[$metric], fn($v)=> $v !== null)) > 0) {
                    $criticals = new PerfdataSeries('critical', $criticalseries[$metric]);
                    $s->addSeries($criticals);
                }
            }

            $pfr->addDataset($s);
        }

        return $pfr;
    }
}

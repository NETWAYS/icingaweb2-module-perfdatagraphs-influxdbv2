<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

use Icinga\Module\Perfdatagraphsinfluxdbv2\Vendor\FluxCsvParser;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Logger;

use GuzzleHttp\Psr7\Response;

/**
 * Transformer handles all data transformation.
 */
class Transformer
{
    /**
     * isIncluded checks if the given metricname is in the given list
     *
     * @param string $metricname name of the metric to find
     * @param array $includeMetrics metrics to include
     * @return bool
     */
    public static function isIncluded(string $metricname, array $includeMetrics = []): bool
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

    /**
     * isExcluded checks if the given metricname is in the given list
     *
     * @param string $metricname name of the metric to find
     * @param array $excludeMetrics metrics to exlude from the response
     * @return bool
     */
    public static function isExcluded(string $metricname, array $excludeMetrics = []): bool
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
     * @param array $includeMetrics metrics to include in the response
     * @param array $excludeMetrics metrics to exlude from the response
     * @return PerfdataResponse
     */
    public static function transform(
        Response $response,
        array $includeMetrics = [],
        array $excludeMetrics = [],
    ): PerfdataResponse {
        $pfr = new PerfdataResponse();

        $stream = new FluxCsvParser($response->getBody(), true);

        $timestamps = [];
        $valueseries = [];
        $warningseries = [];
        $criticalseries = [];
        $units = [];

        foreach ($stream->each() as $record) {
            $metricname = $record['metric'] ?? '';

            if ($metricname === null || $metricname === '') {
                continue;
            }

            if (!self::isIncluded($metricname, $includeMetrics)) {
                continue;
            }

            if (self::isExcluded($metricname, $excludeMetrics)) {
                continue;
            }

            // Do we have a dataset already?
            $dataset = $pfr->getDataset($metricname);

            // No, then create a new one
            if (empty($dataset)) {
                $unit = $record['unit'] ?? '';
                $dataset = new PerfdataSet($metricname, $unit);
                $pfr->addDataset($dataset);
            }

            $dataset->addTimestamp(strtotime($record->getTime()));

            $series = $dataset->getSeries();

            foreach (['value' => 'value', 'warning' => 'warn', 'critical' => 'crit'] as $seriesName => $field) {
                $s = $series[$seriesName] ?? new PerfdataSeries($seriesName);
                $s->addValue($record[$field] ?? null);
                if (!isset($series[$seriesName])) {
                    $dataset->addSeries($s);
                }
            }
        }

        // Remove the empty series from the datasets
        $ds = $pfr->getDatasets();
        foreach ($ds as $dataset) {
            $series = $dataset->getSeries();
            foreach ($series as $ser) {
                if ($ser->isEmpty()) {
                    $dataset->removeSeries($ser->getName());
                }
            }
        }

        return $pfr;
    }
}

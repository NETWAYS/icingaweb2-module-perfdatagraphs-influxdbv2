<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

use GuzzleHttp\Psr7\Stream;

/**
 * InfluxCsvParser takes a CSV Stream and returns nice little InfluxRecords
 */
class InfluxCsvParser
{
    private $response;
    private $resource;
    private $stream;

    public $closed;

    public function __construct(Stream $response)
    {
        $this->response = $response;
        $this->resource = $response->detach();
        $this->closed = false;
    }

    public function each()
    {
        try {
            while (($csv = fgetcsv($this->resource)) !== false) {
                if (!isset($csv) || (count($csv) == 1 && $csv[0] == null)) {
                    continue;
                }

                if ($csv[1] == 'error' && $csv[2] == 'reference') {
                    continue;
                }

                // Skip the header
                if ($csv[0] == 'name') {
                    continue;
                }

                $result = $this->parseLine($csv);

                if ($result instanceof InfluxRecord) {
                    yield $result;
                }
            }
        } finally {
            $this->closeConnection();
        }
    }

    private function parseLine(array $csv): InfluxRecord
    {
        // { [0]=> string(5) "ping6" [1]=> string(9) "metric=pl" [2]=> string(19) "1740386172000000000" [3]=> string(1) "0" }
        $seriesname = $csv[0];
        $metricname = str_replace('metric=', '', $csv[1]);
        $timestamp = $csv[2];
        $value = $csv[3] === '' ? null: floatval($csv[3]);

        $record = new InfluxRecord($seriesname, $metricname, $timestamp, $value);

        return $record;
    }

    private function closeConnection(): void
    {
        # Close CSV Parser
        $this->closed = true;
        if (isset($this->response)) {
            $this->response->close();
        }
        if (is_resource($this->resource)) {
            fclose($this->resource);
        }

        unset($this->response);
        unset($this->resource);
    }
}

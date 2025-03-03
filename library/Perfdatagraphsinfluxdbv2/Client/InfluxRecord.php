<?php

namespace Icinga\Module\Perfdatagraphsinfluxdbv2\Client;

/**
 * InfluxRecord represents a single CSV line
 */
class InfluxRecord
{
    protected string $seriesname;
    protected string $metricname;
    protected int $timestamp;
    protected ?float $value;

    public function __construct(string $seriesname, string $metricname, int $timestamp, ?float $value)
    {
        $this->seriesname = $seriesname;
        $this->metricname = $metricname;
        $this->timestamp = $timestamp;
        $this->value = $value;
    }

    public function getSeriesName(): string
    {
        return $this->seriesname;
    }

    public function getMetricName(): string
    {
        return $this->metricname;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }
}

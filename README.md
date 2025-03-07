# Icinga Web Performance Data Graphs InfluxDBv2 Backend

A InfluxDBv2 backend for the Icinga Web Performance Data Graphs Module.

## Known Issues

### Time range buttons do not adjust when no data is available

When a time range is selected for which there is no data yet
(e.g. a newly created service) the x-axis does not adjust.

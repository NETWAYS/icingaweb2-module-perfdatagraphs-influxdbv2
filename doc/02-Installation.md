# Installation

## Packages

NETWAYS provides this module via [https://packages.netways.de](https://packages.netways.de/).

To install this module, follow the setup instructions for the **extras** repository.

**RHEL or compatible:**

`dnf install icingaweb2-module-perfdatagraphs-influxdbv2`

**Ubuntu/Debian:**

`apt install icingaweb2-module-perfdatagraphs-influxdbv2`

## From source

1. Clone a Icinga Web Performance Data Graphs Backend repository into `/usr/share/icingaweb2/modules/perfdatagraphsinfluxdbv2/`

2. Enable the module using the `Configuration → Modules` menu or the `icingacli`

3. Configure the Influx URL, organization, bucket and authentication using the `Configuration → Modules` menu

# Configuration

| Option                      | Description                                                                                              | Default value            |
|-----------------------------|----------------------------------------------------------------------------------------------------------|--------------------------|
| influx_api_url              | The URL for InfluxDB including the scheme                                                                | `http://localhost:8086`  |
| influx_api_org              | The organization for the bucket                                                                          |  |
| influx_api_bucket           | the bucket for the performance data                                                                      |  |
| influx_api_token            | Token for the authentication                                                                             |  |
| influx_api_timeout          | HTTP timeout for the API in seconds. Should be higher than 0                                             | `10` (seconds)           |
| influx_api_max_data_points  | The maximum numbers of datapoints each series returns. Aggregation can be disabled by setting this to 0. | `10000`                  |
| influx_api_tls_insecure     | Skip the TLS verification                                                                                | `false` (unchecked)      |

`influx_api_max_data_points` is used for downsampling data. The value is used to calculate window sizes for the flux `aggregateWindow` function.
We set`aggregateWindow` to use the `last` selector function, which means, for each windows the last data point is used.
This means, while there is less data in total, each data point will still point to a real check command execution.

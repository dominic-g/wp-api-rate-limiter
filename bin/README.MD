# Instructions
This folder is for building the plugin in development and will not be part of the production ready zip


## Tests
It installs the wordpress test environmnet to be used.

## IP to Country
This is to prevent the plugin from relying on third pary to get visitors country data andproduce it internally

the downside is that the size of the plugin increases by about 13MB but this outweighs having to be reliant onj a third party service that may limit the request or require the users to have an account to use it. Far worse if the third party is down then the data of the visitors wont be captured so its better to have an internal since we only need the country data.


am retrieving the data from https://download.ip2location.com/lite/ so this will be updated occasionally when the data changes.
i download `IP2Location Lite DB1 (IPV4) CSV format` and place it in the `bin` folder and rename the file to `ip_to_country.csv` and run the `build-ip-data.sh` to update the data in file `includes/GeoIP/data/ip_to_country.php`. To check the current data of the ip file check the `changelog.txt` in bin. 

    
### GeoIP Data Setup (For Country Detection)

To enable country-based request logging and filtering, you need to download and process a free IP-to-Country database. This setup is crucial for the "User Distribution by Country" map feature.

1.  **Download IP2Location LITE DB1 (IPV4) CSV:**
    *   Go to the [IP2Location LITE DB1 download page](https://lite.ip2location.com/database/ip-to-country).
    *   Download the **IP2Location Lite DB1 (IPV4)** in **CSV format**.
    *   Rename the downloaded file (e.g., `IP2LOCATION-LITE-DB1.CSV`) to `ip_to_country.csv`.

2.  **Place the CSV File:**
    *   Place `ip_to_country.csv` into the `bin/` directory within your plugin's root folder (`wp-content/plugins/wp-api-rate-limiter/bin/`).

3.  **Process the CSV to Generate PHP Data:**
    *   Open your terminal and navigate to your plugin's root directory:
        ```bash
        cd /path/to/your/wordpress/wp-content/plugins/wp-api-rate-limiter/
        ```
    *   Make the `build-ip-data.sh` script executable if you haven't already:
        ```bash
        chmod +x bin/build-ip-data.sh
        ```
    *   Run the script to generate the PHP data file:
        ```bash
        ./bin/build-ip-data.sh
        ```
        **Note:** This process can take several minutes due to the size of the CSV file. It will create `includes/GeoIP/data/ip_to_country.php`, which serves as a local fallback for GeoIP lookups.

  

ensure the `build-ip-data.sh` is executable `chmod +x bin/build-ip-data.sh` and then run `./bin/build-ip-data.sh`



So an update for the ip check, I have tried to optimise it by reducing the number of call for ip check and backups to ensure we optimise the process and it does not slow us down the ip checking has the following features

    1. Session Cache (WP_Object_Cache): First check.

    2. Database Cache (wp_rlm_geoip_cache): If not in session, check this new table.

    3. API Primary (apip.cc): If not in cache.

    4. API Secondary (ipwho.is): If apip.cc fails.

    5. Local Fallback (includes/GeoIP/data/ip_to_country.php): Last resort if both APIs fail and not in DB.

    6. Smart Caching: Store API results (including rich data) in wp_rlm_geoip_cache for future lookups.

    7. "Unknown" / Failed IPs: Handle false or - from local data / API failures by setting to a placeholder (null for non-country data) and re-checking after 24 hours have passed from the last request.
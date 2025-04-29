# iNaturalistGallery Extension

The `iNaturalistGallery` extension for MediaWiki allows you to embed iNaturalist galleries into your wiki pages. This README provides instructions on installation, configuration, and usage.

## Installation

1. **Download the Extension**  
    Clone or download the `iNaturalistGallery` extension into the `extensions` directory of your MediaWiki installation:
    ```bash
    cd /var/www/html/wiki/extensions
    git clone https://github.com/MatejFranceskin/iNaturalistGallery
    ```

2. **Verify File Placement**  
    Ensure the `iNaturalistGallery.php` file is located in the `iNaturalistGallery` directory:
    ```
    /var/www/html/wiki/extensions/iNaturalistGallery/iNaturalistGallery.php
    ```

3. **Enable the Extension**  
    Add the following line to your `LocalSettings.php` file to enable the extension:
    ```php
    wfLoadExtension('iNaturalistGallery');
    ```

4. **Clear Cache**  
    After enabling the extension, clear your MediaWiki cache:
    ```bash
    php maintenance/update.php
    ```

## Usage

To embed an iNaturalist gallery on a wiki page, use the following syntax:
```wikitext
<iNaturalistGallery taxon="TAXON_NAME" />
```

### Note

If the `taxon` parameter is not specified, the extension will use the page name as the taxon name by default.

### Example Pages

1. **Psathyrella 'alluvinana PNW10'**  
    Create a new page with the following content:
    ```wikitext
    == Psathyrella 'alluvinana PNW10' ==
    <iNaturalistGallery taxon="Psathyrella 'alluvinana PNW10'" />
    ```

2. **Rubroboletus Satanas**  
    Create a new page with the following content:
    ```wikitext
    == Rubroboletus Satanas ==
    <iNaturalistGallery taxon="Rubroboletus Satanas" />
    ```

3. **Using Page Name as Taxon**  
    If you omit the `taxon` parameter, the page name will be used. For example, on a page named `Amanita muscaria`, use:
    ```wikitext
    == Amanita muscaria ==
    <iNaturalistGallery />
    ```
    This will automatically use `Amanita muscaria` as the taxon name.

## Notes

- Ensure your MediaWiki installation has the necessary permissions to load extensions.
- The `taxon` parameter should match the exact name of the taxon on iNaturalist.

## Support

For issues or feature requests, please open an issue on the [GitHub repository](https://github.com//MatejFranceskin/iNaturalistGallery).

## License

This extension is licensed under the MIT License. See the `LICENSE` file for details.
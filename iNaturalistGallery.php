<?php
// filepath: extensions/iNaturalistGallery/iNaturalistGallery.php

if (!defined('MEDIAWIKI')) {
    die("This is a MediaWiki extension and cannot be run standalone.\n");
}

$wgExtensionCredits['parserhook'][] = [
    'name' => 'iNaturalistGallery',
    'author' => 'Matej Franceskin',
    'description' => 'Displays an iNaturalist gallery based on a species name (standard or provisional).',
    'version' => '1.1',
    'url' => 'https://www.mediawiki.org/wiki/Extension:iNaturalistGallery',
];

$wgHooks['ParserFirstCallInit'][] = 'iNaturalistGallery::onParserFirstCallInit';

class iNaturalistGallery {
    private static $logFile = '/var/www/vhosts/mycomap.org/wiki/logs/inat_debug.log';

    public static function onParserFirstCallInit(Parser $parser) {
        $parser->setHook('iNaturalistGallery', [self::class, 'renderGallery']);
        return true;
    }

    private static function logDebug($message) {
        error_log(date('[Y-m-d H:i:s] ') . $message . "\n", 3, self::$logFile);
    }

    public static function renderGallery($input, array $args, Parser $parser, PPFrame $frame) {
        $speciesName = isset($args['species']) ? $args['species'] : $parser->getTitle()->getText();
        if (empty($speciesName)) {
            return '<div class="error">Error: No species name provided.</div>';
        }

        $speciesName = str_replace('_', ' ', $speciesName);
        $apiUrl = 'https://api.inaturalist.org/v1/observations';
        $foundBy = '';
        $data = null;
        $fieldName = 'field:Provisional Species Name'; // Default to provisional field name

        // If name contains quotes it's provisional - skip standard taxonomy search
        if (strpos($speciesName, "'") !== false || strpos($speciesName, '"') !== false) {
            $foundBy = "Provisional Species Name";
            // Try the iNaturalist observation field Provisional Species Name 
            $params = [
                'order_by' => 'id',
                'order' => 'desc',
                'page' => 1,
                'spam' => 'false',
                'field:Provisional Species Name' => $speciesName,
                'per_page' => 24,
                'return_bounds' => 'true',
            ];
            $queryString = http_build_query($params);
            $url = "$apiUrl?$queryString";

            self::logDebug("Fallback to Provisional Species Name: '$speciesName'");
            self::logDebug("Provisional API URL: $url");

            $response = file_get_contents($url);
            if ($response !== false) {
                $data = json_decode($response, true);
                if (!empty($data['results'])) {
                    $foundBy = "Provisional Species Name";
                    $fieldName = 'field:Provisional Species Name'; // Set the field for provisional name
                }
            }
        } else {
            // Step 1: Try to get taxon_id from the iNaturalist standard taxonomy
            $taxonSearchUrl = 'https://api.inaturalist.org/v1/taxa?q=' . urlencode($speciesName);
            self::logDebug("Standard taxonomy search for species: '$speciesName'");
            self::logDebug("Taxon API URL: $taxonSearchUrl");

            $taxonResponse = file_get_contents($taxonSearchUrl);
            if ($taxonResponse !== false) {
                $taxonData = json_decode($taxonResponse, true);
                if (isset($taxonData['results'][0]['id'])) {
                    $taxonId = $taxonData['results'][0]['id'];

                    $params = [
                        'order_by' => 'id',
                        'order' => 'desc',
                        'page' => 1,
                        'spam' => 'false',
                        'taxon_id' => $taxonId,
                        'field:DNA Barcode ITS' => '',
                        'per_page' => 24,
                        'return_bounds' => 'true',
                    ];
                    $queryString = http_build_query($params);
                    $url = "$apiUrl?$queryString";

                    self::logDebug("Standard taxonomy observations search URL: $url");

                    $response = file_get_contents($url);
                    if ($response !== false) {
                        $data = json_decode($response, true);
                        if (!empty($data['results'])) {
                            $foundBy = "standard taxonomy (taxon_id: $taxonId)";
                            $fieldName = 'field:DNA Barcode ITS'; // Set the field for DNA barcode ITS
                        }
                    }
                }
            }
        }

        if (empty($data['results'])) {
            return '<div class="error">No observations found for the given species name.</div>';
        }

        // Summary line
        $count = $data['total_results'] ?? count($data['results']);
        $summary = "<p style='font-weight:bold;'>Searched iNaturalist $foundBy for <em>$speciesName</em> with ITS sequence; total results: <strong>$count</strong>.</p>";

        // Add message if more than 24 results
        if ($count > 24) {
            $summary .= "<p>Showing 24 results out of $count.</p>";
        }

        // Build gallery with only the first 24 results
        $html = $summary;
        $html .= '<div class="inat-gallery" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px;">';

        $limit = min(24, count($data['results']));
        for ($i = 0; $i < $limit; $i++) {
            $observation = $data['results'][$i];
            if (isset($observation['photos'][0])) {
                $photo = $observation['photos'][0];
                $photoUrl = str_replace('square', 'small', $photo['url']);
                $taxonName = isset($observation['taxon']['name']) ? $observation['taxon']['name'] : 'Unknown Taxon';
                $observationUri = $observation['uri'];

                $html .= '<div style="text-align: center;">';
                $html .= '<div style="width: 100%; padding-top: 100%; position: relative; overflow: hidden; border-radius: 5px;">';
                $html .= "<img src=\"$photoUrl\" alt=\"$taxonName\" style=\"position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover;\">";
                $html .= '</div>';
                $html .= "<a href=\"$observationUri\" target=\"_blank\" style=\"display: block; margin-top: 5px; text-decoration: none; color: #007BFF;\">$taxonName</a>";
                $html .= '</div>';
            }
        }

        $html .= '</div>';

        // Link to full list with conditional text and field
        $speciesNameEncoded = urlencode($speciesName);
        $fullUrl = '';

        if ($foundBy === "standard taxonomy (taxon_id: $taxonId)") {
            $fullUrl = "https://www.inaturalist.org/observations?subview=map&taxon_id=$taxonId&field:DNA%20Barcode%20ITS=";
            $linkText = "Sequenced iNaturalist observations for $speciesName";
        } else {
            $fullUrl = "https://www.inaturalist.org/observations?verifiable=any&place_id=any&$fieldName=$speciesNameEncoded";
            $linkText = "iNaturalist observations for provisional species name $speciesName";
        }

        $html .= '<div style="text-align: center; margin-top: 20px;">';
        $html .= "<a href=\"$fullUrl\" target=\"_blank\" style=\"text-decoration: none; color: #007BFF; font-weight: bold;\">$linkText</a>";
        $html .= '</div>';

        return $html;
    }
}

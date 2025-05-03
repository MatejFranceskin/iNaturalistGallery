<?php
// filepath: extensions/iNaturalistGallery/iNaturalistGallery.php

if (!defined('MEDIAWIKI')) {
    die("This is a MediaWiki extension and cannot be run standalone.\n");
}

$wgExtensionCredits['parserhook'][] = [
    'name' => 'iNaturalistGallery',
    'author' => 'Matej Franceskin',
    'description' => 'Displays an iNaturalist gallery based on a species name (standard or provisional).',
    'version' => '1.2',
    'url' => 'https://www.mediawiki.org/wiki/Extension:iNaturalistGallery',
];

// Register the parser hook
$wgHooks['ParserFirstCallInit'][] = 'iNaturalistGallery::onParserFirstCallInit';

class iNaturalistGallery {
    private static $logFile = '/var/www/vhosts/mycomap.org/wiki/logs/inat_debug.log';
    private static $galleryCounter = 0;

    /****
     * Registers the <iNaturalistGallery> parser hook with the MediaWiki parser.
     *
     * @return bool True on successful hook registration.
     */
    public static function onParserFirstCallInit(Parser $parser) {
        $parser->setHook('iNaturalistGallery', [self::class, 'renderGallery']);
        return true;
    }

    /****
     * Writes a timestamped debug message to the extension's log file.
     *
     * @param string $message The debug message to log.
     */
    private static function logDebug($message) {
        error_log(date('[Y-m-d H:i:s] ') . $message . "\n", 3, self::$logFile);
    }

    /**
     * Renders an iNaturalist photo gallery for a given species name, supporting both standard taxonomy and provisional species names.
     *
     * Determines the search method based on the presence of quotes in the species name: if quotes are present, searches iNaturalist observations by the "Provisional Species Name" field; otherwise, attempts to resolve the species to a taxon ID and searches for observations with a DNA Barcode ITS field. Displays up to 24 observation photos in a grid, includes a summary of the search, and provides a link to the full list of relevant iNaturalist observations.
     *
     * @param mixed $input Unused input parameter from the parser hook.
     * @param array $args Arguments passed to the parser tag, including an optional 'species' key.
     * @param Parser $parser MediaWiki parser instance.
     * @param PPFrame $frame Parser frame.
     * @return string HTML markup for the gallery or an error message if no observations are found.
     */
    public static function renderGallery($input, array $args, Parser $parser, PPFrame $frame) {
        // Get species name from tag or page title
        $speciesName = isset($args['species']) ? $args['species'] : $parser->getTitle()->getText();
        if (empty($speciesName)) {
            return '<div class="error">Error: No species name provided.</div>';
        }
        
        // Increment counter to get a unique gallery ID
        self::$galleryCounter++;
        $galleryId = 'inat_gallery_' . self::$galleryCounter . '_' . rand(1000, 9999);
        
        self::logDebug("Rendering gallery with ID: $galleryId for species: $speciesName");

        $speciesName = str_replace('_', ' ', $speciesName);
        $apiUrl = 'https://api.inaturalist.org/v1/observations';
        $foundBy = '';
        $data = null;
        $taxonId = null;

        // If name contains quotes it's definitely provisional - skip standard taxonomy search
        $isProvisionalNameByQuotes = (strpos($speciesName, "'") !== false || strpos($speciesName, '"') !== false);

        if (!$isProvisionalNameByQuotes) {
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
                        'per_page' => 200,
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

        // If standard taxonomy lookup failed or was skipped, try provisional name search
        if (empty($data['results'])) {
            // Handle field:Provisional Species Name parameter properly
            // key MUST stay literal, only the value is encoded
            $provisionalFieldKey   = 'field:Provisional%20Species%20Name';
            $provisionalFieldValue = rawurlencode($speciesName);
            $queryString = "order_by=id&order=desc&page=1&spam=false&{$provisionalFieldKey}={$provisionalFieldValue}&per_page=100&return_bounds=true";
            $url = "$apiUrl?$queryString";
            self::logDebug("Checking Provisional Species Name: '$speciesName'");
            self::logDebug("Provisional API URL: $url");

            $response = file_get_contents($url);
            if ($response !== false) {
                $data = json_decode($response, true);
                if (!empty($data['results'])) {
                    $foundBy = "Provisional Species Name";
                    $fieldName = 'field:Provisional Species Name';
                }
            }
        }

        if (empty($data['results'])) {
            return '<div class="error">No observations found for the given species name.</div>';
        }

        // Summary line - format differently based on whether it's standard taxonomy or provisional
        $count = $data['total_results'] ?? count($data['results']);
        if ($foundBy === "Provisional Species Name") {
            // New format for provisional species names
            $safeName = htmlspecialchars($speciesName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary  = "<p style='font-weight:bold;'>Searched for iNaturalist Provisional Species Name <em>{$safeName}</em>; total results: <strong>{$count}</strong>.</p>";
        } else {
            // Keep existing format for standard taxonomy
            $summary = "<p style='font-weight:bold;'>Searched iNaturalist $foundBy for <em>$speciesName</em> with ITS sequence; total results: <strong>$count</strong>.</p>";
        }

        // Build collections of photos - one per observation and all photos
        $regularPhotos = [];
        $allPhotos = [];
        $totalPhotoCount = 0;
        $locations = [];
        
        // Process all observations
        foreach ($data['results'] as $observation) {
            if (isset($observation['photos']) && is_array($observation['photos'])) {
                $numPhotos = count($observation['photos']);
                $totalPhotoCount += $numPhotos;
                
                // Log observation details
                self::logDebug("Observation ID: {$observation['id']} has $numPhotos photos");
                
                // Add first photo to regular collection
                if (!empty($observation['photos'])) {
                    $photo = $observation['photos'][0];
                    $photoUrl = str_replace('square', 'original', $photo['url']);
                    $smallPhotoUrl = str_replace('square', 'medium', $photo['url']);  // Use medium for regular view
                    $taxonName = isset($observation['taxon']['name']) ? $observation['taxon']['name'] : 'Unknown Taxon';
                    $observationUri = $observation['uri'];
                    
                    $regularPhotos[] = [
                        'url' => $smallPhotoUrl,  // Medium size for regular view
                        'original_url' => $photoUrl,  // Original size for lightbox
                        'taxon' => $taxonName,
                        'uri' => $observationUri,
                        'observation_id' => $observation['id']
                    ];
                    
                    self::logDebug("Added first photo from observation {$observation['id']} to regular display: $smallPhotoUrl");
                }
                
                // Add all photos to all photos collection
                foreach ($observation['photos'] as $index => $photo) {
                    $photoUrl = str_replace('square', 'original', $photo['url']);
                    $smallPhotoUrl = str_replace('square', 'medium', $photo['url']);  // Use medium for thumbnails
                    $taxonName = isset($observation['taxon']['name']) ? $observation['taxon']['name'] : 'Unknown Taxon';
                    $observationUri = $observation['uri'];
                    
                    $allPhotos[] = [
                        'url' => $smallPhotoUrl,  // Medium size for gallery
                        'original_url' => $photoUrl,  // Original size for lightbox
                        'taxon' => $taxonName,
                        'display_name' => $taxonName . " (Photo " . ($index + 1) . " of $numPhotos)",
                        'uri' => $observationUri,
                        'observation_id' => $observation['id'],
                        'photo_index' => $index + 1,
                        'total_photos' => $numPhotos
                    ];
                    
                    self::logDebug("Added photo " . ($index + 1) . " from observation {$observation['id']} to all photos collection: $smallPhotoUrl");
                }
            }
            if (isset($observation['location']) && strpos($observation['location'], ',') !== false) {
                list($latitude, $longitude) = explode(',', $observation['location']);
                $locations[] = [
                    'latitude' => trim($latitude),
                    'longitude' => trim($longitude),
                    'uri' => trim($observation['uri']),
                    'photo' => isset($observation['photos'][0]['url']) ? $observation['photos'][0]['url'] : null
                ];

                self::logDebug("Observation ID: {$observation['id']} has location: $latitude, $longitude");
            }
        }
        
        self::logDebug("Total photos found: $totalPhotoCount");
        self::logDebug("Number of photos in regular mode: " . count($regularPhotos));
        self::logDebug("Number of photos in all photos mode: " . count($allPhotos));

        // Create safe, JSON-encoded JavaScript strings for use in the script
        $regularStatusText = json_encode("Showing one photo from each of " . count($regularPhotos) . " observations. These observations contain a total of $totalPhotoCount photos.");
        
        if ($foundBy === "Provisional Species Name") {
            $safeSpeciesName = json_encode($speciesName);
            $allPhotosStatusText = json_encode("Showing $totalPhotoCount photos of $speciesName.");
            $allPhotosLinkText = json_encode("Show photos of $speciesName");
        } else {
            $safeSpeciesName = json_encode($speciesName);
            $allPhotosStatusText = json_encode("Showing $totalPhotoCount photos of sequenced observations of $speciesName.");
            $allPhotosLinkText = json_encode("Show photos of sequenced observations of $speciesName");
        }
        
        // Extract the string content (without JSON quotes) for use in HTML
        $regularStatusTextHtml = htmlspecialchars(json_decode($regularStatusText), ENT_QUOTES);
        $allPhotosLinkTextHtml = htmlspecialchars(json_decode($allPhotosLinkText), ENT_QUOTES);

        // Build gallery HTML
        $html = $summary;
        $parser->getOutput()->addHeadItem("
            <script src=\"https://unpkg.com/leaflet@1.9.4/dist/leaflet.js\"></script>
            <link rel=\"stylesheet\" href=\"https://unpkg.com/leaflet@1.9.4/dist/leaflet.css\" />
            <script src=\"https://cdn.jsdelivr.net/npm/heatmap.js@2.0.5/build/heatmap.min.js\"></script>
            <script src=\"https://unpkg.com/leaflet-heatmap/leaflet-heatmap.js\"></script>
        ", 'leaflet-heatmap');

        // Add Leaflet heat map container
        $html .= '<div id="heatmap_container" style="width: 100%; height: 500px; margin-bottom: 20px; position: relative;"></div>';
        // Add Leaflet heat map script
        $locationsJson = json_encode($locations);
        $parser->getOutput()->addHeadItem("
            <script type=\"text/javascript\">
                document.addEventListener('DOMContentLoaded', function() {
                    // Initialize the Leaflet map
                    var map = L.map('heatmap_container').setView([0, 0], 2);
        
                    // Add OpenStreetMap tiles
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 18,
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(map);
        
                    // Configure the heatmap.js layer
                    var heatmapLayer = new HeatmapOverlay({
                        radius: 10,          // Initial radius of each heat point
                        maxOpacity: 0.8,     // Maximum opacity of the heatmap
                        scaleRadius: false,  // Disable automatic scaling (we'll handle it manually)
                        useLocalExtrema: true,
                        latField: 'lat',     // Latitude field in data
                        lngField: 'lng',     // Longitude field in data
                        valueField: 'value'  // Intensity field in data
                    });
        
                    // Add the heatmap layer to the map
                    map.addLayer(heatmapLayer);
        
                    // Prepare heatmap data
                    var heatmapData = {
                        max: 10, // Maximum intensity value
                        data: $locationsJson.map(function(loc) {
                            return {
                                lat: loc.latitude,  // Latitude
                                lng: loc.longitude, // Longitude
                                value: 7            // Intensity (adjust as needed)
                            };
                        })
                    };
        
                    // Set the heatmap data
                    heatmapLayer.setData(heatmapData);

                    // Add observation markers as blue dots
                    $locationsJson.forEach(function(loc) {
                        if (loc.latitude && loc.longitude && loc.uri) {
                            // Create a custom blue dot icon
                            var blueDotIcon = L.divIcon({
                                className: 'blue-dot', // Custom class for styling
                                iconSize: [10, 10],    // Size of the dot
                                iconAnchor: [5, 5]     // Center the dot
                            });

                            // Create a marker with the custom icon
                            var marker = L.marker([loc.latitude, loc.longitude], { icon: blueDotIcon }).addTo(map);

                            // Add a click event to open the observation URI
                            marker.on('click', function() {
                                window.open(loc.uri, '_blank');
                            });

                            // Add a hover tooltip with the observation photo
                            if (loc.photo) {
                                marker.bindTooltip(
                                    `<div style=\"text-align: center;\">
                                    <img src=\"` + loc.photo + `\" alt=\"Observation Photo\" style=\"width: 100px; height: 100px; object-fit: cover; border-radius: 5px;\" />
                                    </div>`,
                                    { direction: 'top', offset: [0, -10], opacity: 0.9 }
                                );
                            }
                        }
                    });

                    // Function to update the radius based on zoom level
                    function updateHeatmapRadius() {
                        var zoom = map.getZoom();
                        var newRadius = zoom * 10; // Adjust the multiplier as needed
                        heatmapLayer.cfg.radius = newRadius;
                        heatmapLayer._reset(); // Reset the heatmap to apply the new radius
                        console.log('Updated heatmap radius to:', newRadius);
                    }
        
                    // Update the radius whenever the zoom level changes
                    map.on('zoomend', updateHeatmapRadius);
        
                    // Initialize the radius based on the current zoom level
                    updateHeatmapRadius();
                });
            </script>
            <style>
                .blue-dot {
                    width: 10px;
                    height: 10px;
                    background-color: blue;
                    border-radius: 50%;
                    box-shadow: 0 0 5px rgba(0, 0, 255, 0.5); /* Optional glow effect */
                }
            </style>            
        ", 'leaflet-heatmap-overlay');

        // Status text (changes based on current view)
        $html .= "<p id=\"{$galleryId}_status\">$regularStatusTextHtml</p>";
        
        // Regular gallery container (one photo per observation) - visible by default
        $html .= "<div id=\"{$galleryId}_regular_container\" style=\"display: block;\">";
        
        // Regular gallery (one photo per observation) - 4 columns for regular view
        $html .= "<div id=\"{$galleryId}_regular\" class=\"inat-gallery\" style=\"display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;\">";
        foreach ($regularPhotos as $index => $photo) {
            $html .= '<div class="gallery-item" style="text-align: center;">';
            $html .= '<div style="width: 100%; padding-top: 100%; position: relative; overflow: hidden; border-radius: 5px;">';
            $html .= "<img src=\"{$photo['url']}\" data-original=\"{$photo['original_url']}\" alt=\"{$photo['taxon']}\" style=\"position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; background-color: #f8f8f8;\">";
            $html .= '</div>';
            $html .= "<a href=\"{$photo['uri']}\" target=\"_blank\" style=\"display: block; margin-top: 5px; text-decoration: none; color: #007BFF;\">{$photo['taxon']}</a>";
            $html .= '</div>';
        }
        $html .= "</div>";
        $html .= "</div>";
        
        // All photos container - initially hidden
        $html .= "<div id=\"{$galleryId}_all_container\" style=\"display: none;\">";
        
        // All photos gallery - 3 columns for all photos view to make them larger
        $html .= "<div id=\"{$galleryId}_all\" class=\"inat-gallery\" style=\"display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;\">";
        foreach ($allPhotos as $index => $photo) {
            $html .= '<div class="gallery-item" style="text-align: center;">';
            $html .= '<div style="width: 100%; padding-top: 100%; position: relative; overflow: hidden; border-radius: 5px;">';
            $html .= "<img src=\"{$photo['url']}\" data-original=\"{$photo['original_url']}\" alt=\"{$photo['display_name']}\" style=\"position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; background-color: #f8f8f8;\">";
            $html .= '</div>';
            $html .= "<a href=\"{$photo['uri']}\" target=\"_blank\" style=\"display: block; margin-top: 5px; text-decoration: none; color: #007BFF;\">{$photo['display_name']}</a>";
            $html .= '</div>';
        }
        $html .= "</div>";
        $html .= "</div>";

        // Link to iNaturalist
        $speciesNameEncoded = urlencode($speciesName);
        if ($foundBy === "standard taxonomy (taxon_id: $taxonId)") {
            $fullUrl = "https://www.inaturalist.org/observations?subview=map&taxon_id=$taxonId&field:DNA%20Barcode%20ITS=";
            $linkText = "Sequenced iNaturalist observations for $speciesName";
        } else {
            $provisionalFieldUrlPart = urlencode('field:Provisional Species Name');
            $fullUrl = "https://www.inaturalist.org/observations?verifiable=any&place_id=any&{$provisionalFieldUrlPart}={$speciesNameEncoded}";
            $linkText = "iNaturalist observations for provisional species name $speciesName";
        }

        // Add links section
        $html .= '<div style="text-align: center; margin-top: 20px;">';
        $html .= "<a href=\"$fullUrl\" target=\"_blank\" style=\"text-decoration: none; color: #007BFF; font-weight: bold;\">$linkText</a>";
        $html .= "<br><br>";
        
        // Toggle buttons with more reliable onclick handlers
        $html .= "<button id=\"{$galleryId}_show_all\" style=\"background: none; border: none; color: #007BFF; text-decoration: underline; cursor: pointer; font-weight: bold; padding: 0;\">$allPhotosLinkTextHtml</button>";
        $html .= "<button id=\"{$galleryId}_show_regular\" style=\"background: none; border: none; color: #007BFF; text-decoration: underline; cursor: pointer; font-weight: bold; padding: 0; display: none;\">Show one photo per observation</button>";
        $html .= '</div>';
        
        // Better JavaScript toggle implementation with properly encoded strings
        $parser->getOutput()->addHeadItem("
            <script type=\"text/javascript\">
            (function() {
                // Function to initialize gallery once DOM is loaded
                function initGallery_$galleryId() {
                    console.log('Initializing gallery: $galleryId');
                    
                    // Get DOM elements
                    var showAllBtn = document.getElementById('{$galleryId}_show_all');
                    var showRegularBtn = document.getElementById('{$galleryId}_show_regular');
                    var regularContainer = document.getElementById('{$galleryId}_regular_container');
                    var allContainer = document.getElementById('{$galleryId}_all_container');
                    var statusText = document.getElementById('{$galleryId}_status');
                    
                    // Check if elements exist
                    if (!showAllBtn || !showRegularBtn || !regularContainer || !allContainer || !statusText) {
                        console.error('Gallery elements not found for $galleryId');
                        return;
                    }
                    
                    // Function to show all photos
                    function showAllPhotos() {
                        console.log('Showing all photos for $galleryId');
                        regularContainer.style.display = 'none';
                        allContainer.style.display = 'block';
                        showAllBtn.style.display = 'none';
                        showRegularBtn.style.display = 'inline';
                        statusText.textContent = $allPhotosStatusText;
                        return false;
                    }
                    
                    // Function to show regular gallery
                    function showRegularPhotos() {
                        console.log('Showing regular gallery for $galleryId');
                        allContainer.style.display = 'none';
                        regularContainer.style.display = 'block';
                        showRegularBtn.style.display = 'none';
                        showAllBtn.style.display = 'inline';
                        statusText.textContent = $regularStatusText;
                        return false;
                    }
                    
                    // Add event listeners
                    showAllBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        showAllPhotos();
                    });
                    
                    showRegularBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        showRegularPhotos();
                    });
                    
                    console.log('Gallery $galleryId initialized');
                }
                
                // Initialize when DOM is ready
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initGallery_$galleryId);
                } else {
                    initGallery_$galleryId();
                }
            })();
            </script>
        ", 'inaturalist-gallery-' . $galleryId);
        
        return $html;
    }
}

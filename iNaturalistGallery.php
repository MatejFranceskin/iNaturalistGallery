<?php
// filepath: extensions/iNaturalistGallery/iNaturalistGallery.php

if ( !defined( 'MEDIAWIKI' ) ) {
    die( "This is a MediaWiki extension and cannot be run standalone.\n" );
}

$wgExtensionCredits['parserhook'][] = [
    'name' => 'iNaturalistGallery',
    'author' => 'Your Name',
    'description' => 'Displays an iNaturalist gallery based on a provisional species name.',
    'version' => '1.0',
    'url' => 'https://www.mediawiki.org/wiki/Extension:iNaturalistGallery',
];

// Register the parser hook
$wgHooks['ParserFirstCallInit'][] = 'iNaturalistGallery::onParserFirstCallInit';

class iNaturalistGallery {
    public static function onParserFirstCallInit( Parser $parser ) {
        // Register the <inatgallery> tag
        $parser->setHook( 'inatgallery', [ self::class, 'renderGallery' ] );
        return true;
    }

    public static function renderGallery( $input, array $args, Parser $parser, PPFrame $frame ) {
        // Get the species name from the tag parameter or default to the current page name
        $speciesName = isset( $args['species'] ) ? $args['species'] : $parser->getTitle()->getText();
        if ( empty( $speciesName ) ) {
            return '<div class="error">Error: No species name provided.</div>';
        }
        $speciesName = str_replace('_', ' ', $speciesName);
    
        // Fetch observations from the iNaturalist API using Provisional Species Name
        $apiUrl = 'https://api.inaturalist.org/v1/observations';
        $params = [
            'order_by' => 'id',
            'order' => 'desc',
            'page' => 1,
            'spam' => 'false',
            'field:Provisional Species Name' => $speciesName,
            'per_page' => 24,
            'return_bounds' => 'true',
        ];
    
        $queryString = http_build_query( $params );
        $response = file_get_contents( "$apiUrl?$queryString" );
        if ( $response === false ) {
            return '<div class="error">Error: Unable to fetch data from iNaturalist API.</div>';
        }
    
        $data = json_decode( $response, true );
    
        // If no results are found, fallback to searching by taxon name
        if ( !isset( $data['results'] ) || empty( $data['results'] ) ) {
            $params = [
                'order_by' => 'id',
                'order' => 'desc',
                'page' => 1,
                'spam' => 'false',
                'taxon_name' => $speciesName, // Fallback to taxon name search
                'per_page' => 24,
                'return_bounds' => 'true',
            ];
    
            $queryString = http_build_query( $params );
            $response = file_get_contents( "$apiUrl?$queryString" );
            if ( $response === false ) {
                return '<div class="error">Error: Unable to fetch data from iNaturalist API.</div>';
            }
    
            $data = json_decode( $response, true );
            if ( !isset( $data['results'] ) || empty( $data['results'] ) ) {
                return '<div class="error">No observations found for the given species or taxon name.</div>';
            }
        }
    
        // Generate the gallery HTML
        $html = '<div class="inat-gallery" style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px;">';
        foreach ( $data['results'] as $observation ) {
            if ( isset( $observation['photos'][0] ) ) {
                $photo = $observation['photos'][0];
                $photoUrl = str_replace('square', 'small', $photo['url']);
                $taxonName = isset( $observation['taxon']['name'] ) ? $observation['taxon']['name'] : 'Unknown Taxon';
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
    
        return $html;
    }
}
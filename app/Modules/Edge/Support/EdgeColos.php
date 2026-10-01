<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

/**
 * Where Cloudflare's data centres are, for the workspace's "Served from" map.
 * A colo is named by its nearest airport's IATA code (request.cf.colo).
 *
 * ponytail: hand-kept table of the larger colos at city precision; a colo
 * missing here still lists by code, just without a dot. Cloudflare's own
 * list (speed.cloudflare.com/locations) refuses server requests, so extend
 * this by hand when a site shows an unplaced code.
 */
final class EdgeColos
{
    /** IATA => [city, latitude, longitude] */
    public const PLACES = [
        // North America
        'ATL' => ['Atlanta', 33.64, -84.43], 'BOS' => ['Boston', 42.36, -71.01], 'ORD' => ['Chicago', 41.98, -87.90],
        'DFW' => ['Dallas', 32.90, -97.04], 'DEN' => ['Denver', 39.86, -104.67], 'IAH' => ['Houston', 29.98, -95.34],
        'LAX' => ['Los Angeles', 33.94, -118.41], 'MIA' => ['Miami', 25.79, -80.29], 'MSP' => ['Minneapolis', 44.88, -93.22],
        'EWR' => ['Newark', 40.69, -74.17], 'JFK' => ['New York', 40.64, -73.78], 'PHL' => ['Philadelphia', 39.87, -75.24],
        'PHX' => ['Phoenix', 33.43, -112.01], 'PDX' => ['Portland', 45.59, -122.60], 'SLC' => ['Salt Lake City', 40.79, -111.98],
        'SJC' => ['San Jose', 37.36, -121.93], 'SFO' => ['San Francisco', 37.62, -122.38], 'SEA' => ['Seattle', 47.45, -122.31],
        'IAD' => ['Ashburn', 38.95, -77.46], 'LAS' => ['Las Vegas', 36.08, -115.15], 'MCI' => ['Kansas City', 39.30, -94.71],
        'CLT' => ['Charlotte', 35.21, -80.94], 'DTW' => ['Detroit', 42.21, -83.35], 'MCO' => ['Orlando', 28.43, -81.31],
        'TPA' => ['Tampa', 27.98, -82.53], 'BNA' => ['Nashville', 36.12, -86.68], 'STL' => ['St. Louis', 38.75, -90.37],
        'SAN' => ['San Diego', 32.73, -117.19], 'SMF' => ['Sacramento', 38.70, -121.59], 'HNL' => ['Honolulu', 21.32, -157.92],
        'ANC' => ['Anchorage', 61.17, -149.99], 'YYZ' => ['Toronto', 43.68, -79.63], 'YUL' => ['Montréal', 45.47, -73.74],
        'YVR' => ['Vancouver', 49.19, -123.18], 'YYC' => ['Calgary', 51.13, -114.01], 'YWG' => ['Winnipeg', 49.91, -97.24],
        'QRO' => ['Querétaro', 20.62, -100.19], 'MEX' => ['Mexico City', 19.44, -99.07], 'GDL' => ['Guadalajara', 20.52, -103.31],
        // South America & Caribbean
        'GRU' => ['São Paulo', -23.44, -46.47], 'GIG' => ['Rio de Janeiro', -22.81, -43.25], 'EZE' => ['Buenos Aires', -34.82, -58.54],
        'SCL' => ['Santiago', -33.39, -70.79], 'LIM' => ['Lima', -12.02, -77.11], 'BOG' => ['Bogotá', 4.70, -74.15],
        'MDE' => ['Medellín', 6.16, -75.42], 'UIO' => ['Quito', -0.13, -78.36], 'PTY' => ['Panama City', 9.07, -79.38],
        'SJO' => ['San José', 9.99, -84.20], 'SDQ' => ['Santo Domingo', 18.43, -69.67], 'POA' => ['Porto Alegre', -29.99, -51.17],
        'FOR' => ['Fortaleza', -3.78, -38.53], 'CWB' => ['Curitiba', -25.53, -49.18], 'BSB' => ['Brasília', -15.87, -47.92],
        // Europe
        'LHR' => ['London', 51.47, -0.45], 'MAN' => ['Manchester', 53.35, -2.28], 'EDI' => ['Edinburgh', 55.95, -3.37],
        'DUB' => ['Dublin', 53.42, -6.27], 'AMS' => ['Amsterdam', 52.31, 4.76], 'BRU' => ['Brussels', 50.90, 4.48],
        'CDG' => ['Paris', 49.01, 2.55], 'MRS' => ['Marseille', 43.44, 5.22], 'FRA' => ['Frankfurt', 50.04, 8.56],
        'MUC' => ['Munich', 48.35, 11.79], 'DUS' => ['Düsseldorf', 51.29, 6.77], 'HAM' => ['Hamburg', 53.63, 9.99],
        'TXL' => ['Berlin', 52.56, 13.29], 'BER' => ['Berlin', 52.37, 13.50], 'ZRH' => ['Zürich', 47.46, 8.55],
        'GVA' => ['Geneva', 46.24, 6.11], 'VIE' => ['Vienna', 48.11, 16.57], 'PRG' => ['Prague', 50.10, 14.26],
        'WAW' => ['Warsaw', 52.17, 20.97], 'BUD' => ['Budapest', 47.44, 19.26], 'OTP' => ['Bucharest', 44.57, 26.10],
        'SOF' => ['Sofia', 42.70, 23.41], 'ATH' => ['Athens', 37.94, 23.94], 'MAD' => ['Madrid', 40.47, -3.56],
        'BCN' => ['Barcelona', 41.30, 2.08], 'LIS' => ['Lisbon', 38.77, -9.13], 'MXP' => ['Milan', 45.63, 8.72],
        'FCO' => ['Rome', 41.80, 12.25], 'CPH' => ['Copenhagen', 55.62, 12.65], 'OSL' => ['Oslo', 60.19, 11.10],
        'ARN' => ['Stockholm', 59.65, 17.92], 'HEL' => ['Helsinki', 60.32, 24.96], 'RIX' => ['Riga', 56.92, 23.97],
        'KBP' => ['Kyiv', 50.35, 30.89], 'IST' => ['Istanbul', 41.26, 28.74], 'ZAG' => ['Zagreb', 45.74, 16.07],
        'BEG' => ['Belgrade', 44.82, 20.31], 'LUX' => ['Luxembourg', 49.63, 6.21], 'KEF' => ['Reykjavík', 63.99, -22.62],
        // Middle East & Africa
        'DXB' => ['Dubai', 25.25, 55.36], 'AUH' => ['Abu Dhabi', 24.43, 54.65], 'DOH' => ['Doha', 25.27, 51.61],
        'BAH' => ['Bahrain', 26.27, 50.63], 'KWI' => ['Kuwait City', 29.24, 47.97], 'RUH' => ['Riyadh', 24.96, 46.70],
        'JED' => ['Jeddah', 21.68, 39.16], 'MCT' => ['Muscat', 23.59, 58.28], 'TLV' => ['Tel Aviv', 32.01, 34.89],
        'AMM' => ['Amman', 31.72, 35.99], 'CAI' => ['Cairo', 30.12, 31.41], 'JNB' => ['Johannesburg', -26.14, 28.25],
        'CPT' => ['Cape Town', -33.97, 18.60], 'DUR' => ['Durban', -29.61, 31.12], 'LOS' => ['Lagos', 6.58, 3.32],
        'ACC' => ['Accra', 5.61, -0.17], 'NBO' => ['Nairobi', -1.32, 36.93], 'MBA' => ['Mombasa', -4.03, 39.59],
        'DAR' => ['Dar es Salaam', -6.88, 39.20], 'KGL' => ['Kigali', -1.97, 30.14], 'ADD' => ['Addis Ababa', 8.98, 38.80],
        'CMN' => ['Casablanca', 33.37, -7.59], 'ALG' => ['Algiers', 36.69, 3.22], 'TUN' => ['Tunis', 36.85, 10.23],
        'DKR' => ['Dakar', 14.74, -17.49], 'MRU' => ['Mauritius', -20.43, 57.68],
        // Asia
        'NRT' => ['Tokyo', 35.77, 140.39], 'HND' => ['Tokyo', 35.55, 139.78], 'KIX' => ['Osaka', 34.43, 135.24],
        'FUK' => ['Fukuoka', 33.59, 130.45], 'ICN' => ['Seoul', 37.46, 126.44], 'HKG' => ['Hong Kong', 22.31, 113.91],
        'TPE' => ['Taipei', 25.08, 121.23], 'SIN' => ['Singapore', 1.36, 103.99], 'KUL' => ['Kuala Lumpur', 2.75, 101.71],
        'BKK' => ['Bangkok', 13.69, 100.75], 'SGN' => ['Ho Chi Minh City', 10.82, 106.65], 'HAN' => ['Hanoi', 21.22, 105.81],
        'MNL' => ['Manila', 14.51, 121.02], 'CGK' => ['Jakarta', -6.13, 106.66], 'SUB' => ['Surabaya', -7.38, 112.79],
        'DPS' => ['Denpasar', -8.75, 115.17], 'PNH' => ['Phnom Penh', 11.55, 104.84], 'RGN' => ['Yangon', 16.91, 96.13],
        'BOM' => ['Mumbai', 19.09, 72.87], 'DEL' => ['New Delhi', 28.56, 77.10], 'BLR' => ['Bangalore', 13.20, 77.71],
        'MAA' => ['Chennai', 12.99, 80.17], 'HYD' => ['Hyderabad', 17.24, 78.43], 'CCU' => ['Kolkata', 22.65, 88.45],
        'AMD' => ['Ahmedabad', 23.07, 72.63], 'CMB' => ['Colombo', 7.18, 79.88], 'DAC' => ['Dhaka', 23.84, 90.40],
        'KTM' => ['Kathmandu', 27.70, 85.36], 'KHI' => ['Karachi', 24.91, 67.16], 'LHE' => ['Lahore', 31.52, 74.40],
        'ISB' => ['Islamabad', 33.55, 72.83], 'ALA' => ['Almaty', 43.35, 77.04], 'TAS' => ['Tashkent', 41.26, 69.28],
        'ULN' => ['Ulaanbaatar', 47.85, 106.77], 'PEK' => ['Beijing', 40.08, 116.58], 'PVG' => ['Shanghai', 31.14, 121.81],
        'CAN' => ['Guangzhou', 23.39, 113.30], 'SZX' => ['Shenzhen', 22.64, 113.81], 'CTU' => ['Chengdu', 30.58, 103.95],
        // Oceania
        'SYD' => ['Sydney', -33.95, 151.18], 'MEL' => ['Melbourne', -37.67, 144.84], 'BNE' => ['Brisbane', -27.38, 153.12],
        'PER' => ['Perth', -31.94, 115.97], 'ADL' => ['Adelaide', -34.95, 138.53], 'CBR' => ['Canberra', -35.31, 149.19],
        'AKL' => ['Auckland', -37.01, 174.79], 'CHC' => ['Christchurch', -43.49, 172.53], 'NOU' => ['Nouméa', -22.01, 166.21],
        'GUM' => ['Guam', 13.48, 144.80], 'PPT' => ['Papeete', -17.55, -149.61],
    ];

    /** @return array{city: string, lat: float, lon: float}|null */
    public static function place(string $colo): ?array
    {
        $place = self::PLACES[strtoupper($colo)] ?? null;

        return $place !== null ? ['city' => $place[0], 'lat' => $place[1], 'lon' => $place[2]] : null;
    }
}

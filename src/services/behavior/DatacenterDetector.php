<?php

declare(strict_types=1);

namespace larikmc\antibot\services\behavior;

use larikmc\antibot\services\IpMatcher;

/**
 * High-performance offline Cloud / Datacenter IP Detector.
 *
 * Scrapers, headless bots, credential stuffers, and automated tools are predominantly
 * hosted in cloud datacenters (Hetzner, OVH, DigitalOcean, AWS, etc.).
 *
 * Operates purely offline via bitwise CIDR matching without external APIs or third-party fees.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class DatacenterDetector
{
    /**
     * Curated map of leading datacenter providers and their principal IP subnets.
     *
     * @var array<string, array{name: string, asn: string, subnets: list<string>}>
     */
    protected static array $providers = [
        'hetzner' => [
            'name' => 'Hetzner Online',
            'asn' => 'AS24940',
            'subnets' => [
                '78.46.0.0/15',
                '88.198.0.0/16',
                '94.130.0.0/16',
                '95.216.0.0/15',
                '116.202.0.0/15',
                '128.140.0.0/15',
                '135.181.0.0/16',
                '136.243.0.0/16',
                '138.201.0.0/16',
                '142.132.0.0/16',
                '144.76.0.0/16',
                '148.251.0.0/16',
                '159.69.0.0/16',
                '168.119.0.0/16',
                '176.9.0.0/16',
                '188.40.0.0/16',
                '195.201.0.0/16',
                '213.133.96.0/19',
                '213.239.192.0/18',
            ],
        ],
        'digitalocean' => [
            'name' => 'DigitalOcean',
            'asn' => 'AS14061',
            'subnets' => [
                '104.131.0.0/16',
                '104.248.0.0/16',
                '138.68.0.0/16',
                '138.197.0.0/16',
                '142.93.0.0/16',
                '143.198.0.0/16',
                '143.244.128.0/17',
                '146.190.0.0/16',
                '157.230.0.0/16',
                '157.245.0.0/16',
                '159.65.0.0/16',
                '159.89.0.0/16',
                '159.203.0.0/16',
                '161.35.0.0/16',
                '164.90.128.0/17',
                '164.92.128.0/17',
                '165.22.0.0/16',
                '165.227.0.0/16',
                '167.71.0.0/16',
                '167.99.0.0/16',
                '174.138.0.0/16',
                '178.62.0.0/16',
                '188.166.0.0/16',
                '206.189.0.0/16',
                '207.154.192.0/18',
                '209.97.128.0/17',
            ],
        ],
        'ovh' => [
            'name' => 'OVHcloud',
            'asn' => 'AS16276',
            'subnets' => [
                '51.68.0.0/14',
                '51.75.0.0/16',
                '51.77.0.0/16',
                '51.83.0.0/16',
                '51.89.0.0/16',
                '51.91.0.0/16',
                '51.159.0.0/16',
                '51.178.0.0/16',
                '51.210.0.0/16',
                '51.254.0.0/15',
                '54.36.0.0/15',
                '54.38.0.0/15',
                '137.74.0.0/16',
                '141.94.0.0/15',
                '142.44.128.0/17',
                '144.217.0.0/16',
                '147.135.0.0/16',
                '149.202.0.0/16',
                '151.80.0.0/16',
                '152.228.0.0/16',
                '158.69.0.0/16',
                '178.32.0.0/15',
                '188.165.0.0/16',
                '198.27.64.0/18',
                '217.182.0.0/16',
            ],
        ],
        'aws' => [
            'name' => 'Amazon Web Services (AWS)',
            'asn' => 'AS16509',
            'subnets' => [
                '3.0.0.0/9',
                '13.32.0.0/11',
                '18.128.0.0/9',
                '34.192.0.0/10',
                '35.152.0.0/13',
                '44.192.0.0/10',
                '52.0.0.0/11',
                '54.0.0.0/9',
            ],
        ],
        'vultr' => [
            'name' => 'Vultr / Choopa',
            'asn' => 'AS20473',
            'subnets' => [
                '45.32.0.0/16',
                '45.63.0.0/16',
                '45.76.0.0/15',
                '66.42.32.0/19',
                '108.61.0.0/16',
                '149.28.0.0/16',
                '207.246.64.0/18',
            ],
        ],
        'linode' => [
            'name' => 'Linode / Akamai',
            'asn' => 'AS63949',
            'subnets' => [
                '45.33.0.0/16',
                '45.56.64.0/18',
                '45.79.0.0/16',
                '139.144.0.0/16',
                '172.104.0.0/15',
                '173.230.128.0/17',
                '173.255.192.0/18',
                '192.155.80.0/20',
                '198.58.96.0/19',
            ],
        ],
        'scaleway' => [
            'name' => 'Scaleway',
            'asn' => 'AS12876',
            'subnets' => [
                '51.15.0.0/16',
                '51.158.0.0/15',
                '163.172.0.0/16',
                '195.154.0.0/16',
                '212.47.224.0/19',
                '212.83.128.0/18',
            ],
        ],
        'selectel' => [
            'name' => 'Selectel',
            'asn' => 'AS49505',
            'subnets' => [
                '77.244.208.0/20',
                '79.137.128.0/17',
                '82.202.160.0/19',
                '95.213.128.0/17',
                '178.249.240.0/20',
                '188.93.208.0/20',
                '188.225.16.0/20',
                '193.106.111.0/24',
                '195.19.96.0/20',
            ],
        ],
    ];

    /**
     * Inspects the client IP and returns datacenter provider metadata if matched.
     *
     * @param string $ip
     * @return array{provider: string, name: string, asn: string, subnet: string}|null
     */
    public static function detect(string $ip): ?array
    {
        $cleanIp = trim($ip);
        if ($cleanIp === '' || !filter_var($cleanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        foreach (self::$providers as $key => $info) {
            foreach ($info['subnets'] as $subnet) {
                if (IpMatcher::match($cleanIp, [$subnet])) {
                    return [
                        'provider' => $key,
                        'name' => $info['name'],
                        'asn' => $info['asn'],
                        'subnet' => $subnet,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Returns true if client IP belongs to any known datacenter provider.
     *
     * @param string $ip
     * @return bool
     */
    public static function isDatacenter(string $ip): bool
    {
        return self::detect($ip) !== null;
    }

    /**
     * Returns all registered datacenter providers and their CIDR count.
     *
     * @return array<string, array{name: string, asn: string, count: int}>
     */
    public static function getSummary(): array
    {
        $summary = [];
        foreach (self::$providers as $k => $info) {
            $summary[$k] = [
                'name' => $info['name'],
                'asn' => $info['asn'],
                'count' => count($info['subnets']),
            ];
        }
        return $summary;
    }
}

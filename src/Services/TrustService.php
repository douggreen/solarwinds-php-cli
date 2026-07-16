<?php

namespace SolarWinds\Services;

/**
 * Evaluates a source's trust against a configurable ring of trust.
 *
 * Trust is a graduated, geography-aware lean: known-internal IPs are fully
 * trusted, and trust attenuates outward through city, region, and country
 * rings as configured. This service only answers "how trusted is this source,
 * and why" - the numeric value is applied to blocking decisions elsewhere.
 *
 * Config shape (~/.solarwinds.yml), all optional and empty by default so the
 * open-source tool ships with no trust assumptions:
 *
 *   trust:
 *     levels:            # named aliases -> numeric trust in [0,1]
 *       absolute: 1.0
 *       high: 0.85
 *       medium: 0.55
 *       low: 0.30
 *     rings:             # ordered most-specific first; first match wins
 *       - { name: internal, ips: [198.94.157.0/27], trust: absolute }
 *       - { name: bay-area, cities: [San Francisco, Oakland], trust: 0.85 }
 *       - { name: california, regions: [CA], trust: 0.60 }
 *       - { name: united-states, countries: [US], trust: 0.25 }
 *
 * A ring's trust may be a raw number or the name of a configured level.
 */
class TrustService
{
  protected array $rings;
  protected array $levels;

  public function __construct(protected ConfigurationService $config)
  {
    $trust = $this->config->getTrustConfig();
    $this->levels = $trust['levels'] ?? [];
    $this->rings = $trust['rings'] ?? [];
  }

  /**
   * Evaluate the trust of a source against the configured rings.
   *
   * Rings are tried in configuration order (most-specific first); the first
   * that matches wins. An unmatched source is fully untrusted.
   *
   * @param string|null $ip Source IP address.
   * @param string|null $city Geo city.
   * @param string|null $region Geo region/state code.
   * @param string|null $country Geo country code.
   * @return array{trust: float, ring: ?string, matched_on: ?string}
   */
  public function evaluate(?string $ip, ?string $city, ?string $region, ?string $country): array
  {
    foreach ($this->rings as $ring) {
      $matchedOn = $this->matchRing($ring, $ip, $city, $region, $country);
      if ($matchedOn !== NULL) {
        return [
          'trust' => $this->resolveTrust($ring['trust'] ?? 0),
          'ring' => $ring['name'] ?? 'unnamed',
          'matched_on' => $matchedOn,
        ];
      }
    }

    return ['trust' => 0.0, 'ring' => NULL, 'matched_on' => NULL];
  }

  /**
   * Return the first field a ring matches on, or NULL if it doesn't match.
   *
   * @return string|null A "field:value" tag describing the match, or NULL.
   */
  protected function matchRing(array $ring, ?string $ip, ?string $city, ?string $region, ?string $country): ?string
  {
    if ($ip !== NULL && !empty($ring['ips'])) {
      foreach ($ring['ips'] as $cidr) {
        if ($this->ipInCidr($ip, (string) $cidr)) {
          return "ip:$cidr";
        }
      }
    }
    if ($city !== NULL && $this->matchesList($city, $ring['cities'] ?? [])) {
      return "city:$city";
    }
    if ($region !== NULL && $this->matchesList($region, $ring['regions'] ?? [])) {
      return "region:$region";
    }
    if ($country !== NULL && $this->matchesList($country, $ring['countries'] ?? [])) {
      return "country:$country";
    }
    return NULL;
  }

  /**
   * Case-insensitive membership test.
   */
  protected function matchesList(string $value, array $list): bool
  {
    $needle = strtolower(trim($value));
    if ($needle === '' || $needle === 'unknown') {
      return FALSE;
    }
    foreach ($list as $item) {
      if (strtolower(trim((string) $item)) === $needle) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Resolve a trust setting (raw number or named level) to a value in [0,1].
   */
  protected function resolveTrust(mixed $trust): float
  {
    if (is_string($trust)) {
      $trust = $this->levels[$trust] ?? 0;
    }
    return max(0.0, min(1.0, (float) $trust));
  }

  /**
   * Test whether an IPv4 address falls within a CIDR (or matches an exact IP).
   *
   * IPv6 sources match only on exact string equality; ranged IPv6 rings are not
   * supported yet, so IPv6 trust should be expressed via geo rings.
   */
  protected function ipInCidr(string $ip, string $cidr): bool
  {
    if (strpos($cidr, '/') === FALSE) {
      return $ip === $cidr;
    }
    [$subnet, $bits] = explode('/', $cidr, 2);
    $ipLong = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === FALSE || $subnetLong === FALSE) {
      return FALSE;
    }
    $bits = (int) $bits;
    if ($bits <= 0) {
      return TRUE;
    }
    $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
    return ($ipLong & $mask) === ($subnetLong & $mask);
  }
}

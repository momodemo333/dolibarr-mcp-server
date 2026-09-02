<?php

declare(strict_types=1);

namespace DolibarrMcp\Config;

/**
 * What the host module knows about the installation it runs in.
 *
 * The MCP package cannot work this out on its own: it has no access to
 * Dolibarr's constants or database, and it does not know which module embeds
 * it. The host builds this object — three lines — and passes it to Bootstrap,
 * the same way it passes the SQL capability.
 *
 * Everything is optional. A host that supplies nothing still gets a usable
 * answer built from what PHP itself reports, rather than an error.
 */
final class EnvironmentInfo
{
    /**
     * @param string|null        $dolibarrVersion Dolibarr's own version, e.g. "21.0.1"
     * @param string|null        $hostModule      module slug embedding this server, e.g. "dalfred"
     * @param string|null        $hostVersion     that module's version, e.g. "2.30.0"
     * @param array<int, string> $enabledModules  Dolibarr modules currently enabled
     * @param int|null           $entity          current Dolibarr entity id
     * @param bool               $multicompany    whether the Multicompany module is active
     * @param array<string,bool> $capabilities    what this MCP session actually grants
     */
    public function __construct(
        public readonly ?string $dolibarrVersion = null,
        public readonly ?string $hostModule = null,
        public readonly ?string $hostVersion = null,
        public readonly array $enabledModules = [],
        public readonly ?int $entity = null,
        public readonly bool $multicompany = false,
        public readonly array $capabilities = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'dolibarr_version' => $this->dolibarrVersion,
            'php_version' => PHP_VERSION,
            'mcp_server_version' => Version::SERVER,
        ];

        if ($this->hostModule !== null) {
            $out['host_module'] = [
                'name' => $this->hostModule,
                'version' => $this->hostVersion,
            ];
        }

        if ($this->enabledModules !== []) {
            $out['enabled_modules'] = $this->enabledModules;
        }

        if ($this->entity !== null) {
            $out['entity'] = $this->entity;
        }

        $out['multicompany'] = $this->multicompany;
        $out['capabilities'] = $this->capabilities;

        return $out;
    }
}

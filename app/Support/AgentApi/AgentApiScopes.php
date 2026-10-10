<?php

namespace App\Support\AgentApi;

final class AgentApiScopes
{
    public const string IDENTITY_READ = 'identity:read';

    public const string PATIENTS_READ = 'patients:read';

    public const string PATIENTS_WRITE = 'patients:write';

    public const string CLINICAL_READ = 'clinical:read';

    public const string CLINICAL_WRITE = 'clinical:write';

    public const string DOCUMENTS_READ = 'documents:read';

    public const string DOCUMENTS_WRITE = 'documents:write';

    public const string IMPORTS_READ = 'imports:read';

    public const string IMPORTS_WRITE = 'imports:write';

    public const string EXPORTS_READ = 'exports:read';

    public const string EXPORTS_WRITE = 'exports:write';

    public const string RECONCILIATION_READ = 'reconciliation:read';

    public const string RECONCILIATION_WRITE = 'reconciliation:write';

    public const string MCP_USE = 'mcp:use';

    public const string GENAI_READ = 'genai:read';

    public const string GENAI_WORK = 'genai:work';

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        return [
            self::IDENTITY_READ => 'Read your account identity and granted scopes',
            self::PATIENTS_READ => 'List and read patients you can access',
            self::PATIENTS_WRITE => 'Create new patients you own',
            self::CLINICAL_READ => 'Read clinical records for patients you can access',
            self::CLINICAL_WRITE => 'Create and update clinical records you can manage',
            self::DOCUMENTS_READ => 'Read document metadata and download authorized files',
            self::DOCUMENTS_WRITE => 'Upload patient documents',
            self::IMPORTS_READ => 'Read import jobs and extraction results',
            self::IMPORTS_WRITE => 'Create and review import jobs',
            self::EXPORTS_READ => 'Read export and backup status and download results',
            self::EXPORTS_WRITE => 'Request exports and native backups',
            self::RECONCILIATION_READ => 'Preview administrative reconciliation',
            self::RECONCILIATION_WRITE => 'Apply an explicitly confirmed reconciliation',
            self::MCP_USE => 'Connect through the PHR MCP server',
            self::GENAI_READ => 'Read your private external GenAI processing queue status',
            self::GENAI_WORK => 'Claim, download, and complete your private external GenAI work',
        ];
    }

    /**
     * Reserved names document the intended least-privilege split, but are not
     * registered with Passport until the corresponding operation ships. This
     * prevents old refresh tokens from silently gaining future capabilities.
     *
     * @return array<string, string>
     */
    public static function reservedDescriptions(): array
    {
        return [
        ];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::descriptions());
    }

    /**
     * The scope ceiling of the REST resource (APP_URL/api/v1): every module
     * scope, never the MCP connection scope.
     *
     * @return list<string>
     */
    public static function restIds(): array
    {
        return array_values(array_diff(self::ids(), [self::MCP_USE]));
    }

    /**
     * The scope ceiling of the MCP resource (APP_URL/api/v1/mcp): the connection
     * scope plus every module scope, because each MCP tool still requires the
     * scope of the REST operation it adapts.
     *
     * @return list<string>
     */
    public static function mcpIds(): array
    {
        return self::ids();
    }

    /** @return list<string> */
    public static function parse(string $value): array
    {
        return array_values(array_unique(array_filter(
            preg_split('/\s+/', trim($value)) ?: [],
            static fn (string $scope): bool => $scope !== '',
        )));
    }
}

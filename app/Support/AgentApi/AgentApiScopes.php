<?php

namespace App\Support\AgentApi;

final class AgentApiScopes
{
    public const string IDENTITY_READ = 'identity:read';

    public const string PATIENTS_READ = 'patients:read';

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

    /** @return array<string, string> */
    public static function descriptions(): array
    {
        return [
            self::IDENTITY_READ => 'Read your account identity and granted scopes',
            self::PATIENTS_READ => 'List and read patients you can access',
            self::CLINICAL_READ => 'Read clinical records for patients you can access',
            self::CLINICAL_WRITE => 'Create and update clinical records you can manage',
            self::DOCUMENTS_READ => 'Read document metadata and download authorized files',
            self::DOCUMENTS_WRITE => 'Upload and update patient documents',
            self::IMPORTS_READ => 'Read import jobs and extraction results',
            self::IMPORTS_WRITE => 'Create and review import jobs',
            self::EXPORTS_READ => 'Read export and backup status and download results',
            self::EXPORTS_WRITE => 'Request exports and native backups',
            self::RECONCILIATION_READ => 'Preview administrative reconciliation',
            self::RECONCILIATION_WRITE => 'Apply an explicitly confirmed reconciliation',
            self::MCP_USE => 'Connect through the PHR MCP server',
        ];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::descriptions());
    }
}

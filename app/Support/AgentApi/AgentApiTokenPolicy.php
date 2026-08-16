<?php

namespace App\Support\AgentApi;

final class AgentApiTokenPolicy
{
    public const int ACCESS_TOKEN_LIFETIME_MINUTES = 15;

    public const int REFRESH_TOKEN_LIFETIME_DAYS = 30;

    /**
     * Passport refresh rows identify their parent access-token row. Keep expired
     * parents one daily-purge interval beyond the refresh lifetime so cleanup can
     * never shorten the advertised 30-day grant when scheduler timing drifts.
     */
    public const int EXPIRED_CREDENTIAL_RETENTION_HOURS = 31 * 24;
}

<?php

namespace App\Support\AgentApi;

final class AgentApiTokenPolicy
{
    public const int ACCESS_TOKEN_LIFETIME_MINUTES = 15;

    public const int REFRESH_TOKEN_LIFETIME_DAYS = 30;
}

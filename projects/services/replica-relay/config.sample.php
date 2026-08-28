<?php
return [
    // Both paths must be private and outside the document root.
    'database' => '/secure/zogograph-replica-relay/service.db',
    'artifact_directory' => '/secure/zogograph-replica-relay/artifacts',
    'maximum_artifact_bytes' => 5368709120, // 5 GiB; set for the VPS capacity and plan.
    'maximum_tenant_bytes' => 21474836480, // 20 GiB; later billing adapters may apply per-tenant plans.
    'maximum_tenant_artifacts' => 2000,
    'require_https' => true,
];

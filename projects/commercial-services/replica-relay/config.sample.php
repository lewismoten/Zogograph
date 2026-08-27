<?php
return [
    // Both paths must be private and outside the document root.
    'database' => '/secure/hostscope-replica-relay/service.db',
    'artifact_directory' => '/secure/hostscope-replica-relay/artifacts',
    'maximum_artifact_bytes' => 5368709120, // 5 GiB; set for the VPS capacity and plan.
    'require_https' => true,
];

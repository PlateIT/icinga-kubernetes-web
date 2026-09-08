<?php

/** @var \Icinga\Application\Modules\Module $this */

// Version 2 has no direct database, notification or metrics hooks. The trusted
// module backend accesses Kubernetes data exclusively through the central API.

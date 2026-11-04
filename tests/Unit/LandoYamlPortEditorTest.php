<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LandoYamlPortEditor;
use PHPUnit\Framework\TestCase;

class LandoYamlPortEditorTest extends TestCase
{
    public function test_replaces_existing_database_port_and_keeps_other_lines(): void
    {
        $yaml = "name: demo\nservices:\n  database:\n    portforward: 32905\n  cache:\n    type: redis:7.4\n    portforward: true\n";

        $result = (new LandoYamlPortEditor)->withDatabasePort($yaml, 32787);

        $this->assertSame(str_replace('32905', '32787', $yaml), $result);
    }

    public function test_replaces_dynamic_database_port_with_fixed_port(): void
    {
        $yaml = "name: demo\nservices:\n  database:\n    portforward: true # Docker picks one\n  cache:\n    portforward: true\n";

        $result = (new LandoYamlPortEditor)->withDatabasePort($yaml, 32801);

        $this->assertSame("name: demo\nservices:\n  database:\n    portforward: 32801 # Docker picks one\n  cache:\n    portforward: true\n", $result);
    }

    public function test_adds_portforward_when_database_block_has_none(): void
    {
        $yaml = "name: demo\nservices:\n  database:\n    type: mariadb\n  cache:\n    portforward: true\n";

        $result = (new LandoYamlPortEditor)->withDatabasePort($yaml, 32801);

        $this->assertSame("name: demo\nservices:\n  database:\n    portforward: 32801\n    type: mariadb\n  cache:\n    portforward: true\n", $result);
    }

    public function test_adds_database_block_when_services_has_none(): void
    {
        $yaml = "name: demo\nservices:\n  cache:\n    portforward: true\n";

        $result = (new LandoYamlPortEditor)->withDatabasePort($yaml, 32801);

        $this->assertSame("name: demo\nservices:\n  database:\n    portforward: 32801\n  cache:\n    portforward: true\n", $result);
    }

    public function test_appends_services_block_when_missing(): void
    {
        $result = (new LandoYamlPortEditor)->withDatabasePort("name: demo\n", 32801);

        $this->assertSame("name: demo\nservices:\n  database:\n    portforward: 32801\n", $result);
    }

    public function test_keeps_crlf_line_endings(): void
    {
        $yaml = "name: demo\r\nservices:\r\n  database:\r\n    portforward: 32905\r\n";

        $result = (new LandoYamlPortEditor)->withDatabasePort($yaml, 32787);

        $this->assertSame("name: demo\r\nservices:\r\n  database:\r\n    portforward: 32787\r\n", $result);
    }

    public function test_does_not_touch_portforward_of_other_services(): void
    {
        $yaml = "services:\n  cache:\n    portforward: 32955\n  database:\n    portforward: 32905\n";

        $result = (new LandoYamlPortEditor)->withDatabasePort($yaml, 32787);

        $this->assertSame("services:\n  cache:\n    portforward: 32955\n  database:\n    portforward: 32787\n", $result);
    }
}

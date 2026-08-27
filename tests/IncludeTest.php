<?php

declare(strict_types=1);

namespace Go\VirtualFileSystem\Tests;

final class IncludeTest extends VfsTestCase
{
    public function testIncludeReturnsValueFromVirtualFile(): void
    {
        file_put_contents('vfs://config.php', '<?php return ["debug" => true, "answer" => 42];');

        $config = include $this->fs->path('/config.php');

        self::assertSame(['debug' => true, 'answer' => 42], $config);
    }

    public function testRequireDefinesSymbols(): void
    {
        $class = 'GeneratedClass' . bin2hex(random_bytes(6));
        file_put_contents('vfs://gen.php', sprintf('<?php class %s { public const int VALUE = 7; }', $class));

        require $this->fs->path('/gen.php');

        self::assertTrue(class_exists($class, false));
        self::assertSame(7, constant($class . '::VALUE'));
    }
}

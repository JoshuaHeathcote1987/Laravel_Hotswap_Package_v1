<?php

namespace JoshLogic\Hotswap\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CreateModuleCommand extends Command
{
    protected $signature = 'hotswap:create {name}';
    protected $description = 'Create a new module inside the packages directory and register it with Laravel';

    public function handle()
    {
        $name   = $this->argument('name');
        $studly = Str::studly($name);   // Ecommerce
        $lower  = Str::lower($name);    // ecommerce

        $rootPath   = base_path('packages');
        $modulePath = $rootPath . '/' . $lower;

        // Step 1: Ensure packages/ exists
        if (!File::exists($rootPath)) {
            File::makeDirectory($rootPath, 0755, true);
            $this->info("Created packages directory.");
        }

        // Step 2: Ensure module does not already exist
        if (File::exists($modulePath)) {
            $this->error("Module '{$lower}' already exists!");
            return 1;
        }

        // Step 3: Find the stub structure inside hotswap package
        $stubPath = base_path('vendor/joshlogic/hotswap/src/file_struct');

        if (!File::exists($stubPath)) {
            $this->error("Could not find file_struct at: {$stubPath}");
            return 1;
        }

        // Step 4: Copy the stub structure into the new module
        File::copyDirectory($stubPath, $modulePath);

        // Step 5: Replace placeholders and rename files/folders
        $this->replaceInPhpFiles($modulePath, $studly, $lower);
        $this->renameFiles($modulePath, $studly, $lower);
        $this->renameDirectoriesDeepestFirst($modulePath, $studly, $lower);

        // Step 6: Update the module's own AppServiceProvider to load routes
        $this->updateModuleServiceProvider($studly, $lower, $modulePath);

        // Step 7: Update Laravel root files (providers.php, composer.json, vite.config.ts)
        $this->updateProvidersPhp($studly);
        $this->updateComposerJson($studly, $lower);
        $this->updateViteConfig($lower);

        $this->updateRootAppTsx();

        $this->info("✅ Module '{$studly}' created and registered successfully at {$modulePath}");
        return 0;
    }

    protected function replaceInPhpFiles(string $basePath, string $studly, string $lower): void
    {
        foreach (File::allFiles($basePath) as $file) {
            if (Str::endsWith($file->getFilename(), '.php')) {
                $contents = File::get($file->getRealPath());
                $contents = str_replace('Placeholder', $studly, $contents);
                $contents = str_replace('placeholder', $lower, $contents);
                File::put($file->getRealPath(), $contents);
            }
        }
    }

    protected function renameFiles(string $basePath, string $studly, string $lower): void
    {
        $files = File::allFiles($basePath);

        foreach ($files as $file) {
            $oldName = $file->getFilename();
            $newName = str_replace(['Placeholder', 'placeholder'], [$studly, $lower], $oldName);

            if ($newName !== $oldName) {
                $newPath = $file->getPath() . DIRECTORY_SEPARATOR . $newName;
                if (!File::exists($newPath)) {
                    File::move($file->getRealPath(), $newPath);
                }
            }
        }
    }

    protected function renameDirectoriesDeepestFirst(string $basePath, string $studly, string $lower): void
    {
        $dirs = $this->allDirectoriesRecursive($basePath);
        usort($dirs, fn($a, $b) => strlen($b) <=> strlen($a));

        foreach ($dirs as $dir) {
            $base = basename($dir);
            $newBase = str_replace(['Placeholder', 'placeholder'], [$studly, $lower], $base);

            if ($newBase !== $base) {
                $newPath = dirname($dir) . DIRECTORY_SEPARATOR . $newBase;
                if (!File::exists($newPath)) {
                    File::move($dir, $newPath);
                }
            }
        }
    }

    protected function allDirectoriesRecursive(string $path): array
    {
        $result = [];
        foreach (File::directories($path) as $dir) {
            $result[] = $dir;
            $result = array_merge($result, $this->allDirectoriesRecursive($dir));
        }
        return $result;
    }

    protected function updateProvidersPhp(string $studly): void
    {
        $file = base_path('bootstrap/providers.php');
        $line = "    {$studly}\\App\\Providers\\AppServiceProvider::class,";

        $contents = file_get_contents($file);

        if (strpos($contents, $line) === false) {
            $contents = str_replace("];", "{$line}\n];", $contents);
            file_put_contents($file, $contents);
            $this->line("🔹 Added {$studly} provider to bootstrap/providers.php");
        } else {
            $this->line("ℹ️ Provider already exists in bootstrap/providers.php");
        }
    }

    protected function updateComposerJson(string $studly, string $lower): void
    {
        $file = base_path('composer.json');
        $json = json_decode(file_get_contents($file), true);

        // ✅ Fix: Only single backslashes in PHP namespaces
        $autoloadKey = "{$studly}\\App\\";
        $autoloadVal = "packages/{$lower}/src/App/";

        if (!isset($json['autoload']['psr-4'][$autoloadKey])) {
            $json['autoload']['psr-4'][$autoloadKey] = $autoloadVal;
            $this->line("🔹 Added PSR-4 autoload for {$studly} to composer.json");
        }

        // Provider FQCN for Laravel discovery
        $providerClass = "Packages\\{$studly}\\Src\\App\\Providers\\AppServiceProvider";

        if (!in_array($providerClass, $json['extra']['laravel']['providers'] ?? [])) {
            $json['extra']['laravel']['providers'][] = $providerClass;
            $this->line("🔹 Added provider {$providerClass} to composer.json");
        }

        file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function updateViteConfig(string $lower): void
    {
        $file = base_path('vite.config.ts');
        if (!File::exists($file)) {
            $this->warn("vite.config.ts not found, skipping update.");
            return;
        }

        $contents = File::get($file);

        $inputLine = "                'packages/{$lower}/src/resources/js/app.tsx',";
        $aliasLine = "            '@{$lower}': path.resolve(__dirname, 'packages/{$lower}/src/resources/js'),";

        // 1️⃣ Add module to laravel input array
        if (strpos($contents, $inputLine) === false) {
            $contents = preg_replace_callback(
                '/laravel\s*\(\s*\{\s*input\s*:\s*\[([\s\S]*?)\n\s*\],/m',
                function ($matches) use ($inputLine) {
                    $block = trim($matches[1]);

                    // Ensure last line has a comma
                    $lines = explode("\n", $block);
                    if (!str_ends_with(trim(end($lines)), ',')) {
                        $lines[count($lines) - 1] .= ',';
                    }
                    $block = implode("\n", $lines);

                    return "laravel({\n    input: [\n{$block}\n{$inputLine}\n],";
                },
                $contents
            );
            $this->line("🔹 Added '{$lower}' input line to Vite laravel input");
        }

        // 2️⃣ Add alias for module
        if (strpos($contents, $aliasLine) === false) {
            $contents = preg_replace_callback(
                '/alias\s*:\s*\{\s*([\s\S]*?)\n\s*\},/m',
                function ($matches) use ($aliasLine) {
                    $block = trim($matches[1]);

                    // Ensure last line has a comma
                    $lines = explode("\n", $block);
                    if (!str_ends_with(trim(end($lines)), ',')) {
                        $lines[count($lines) - 1] .= ',';
                    }
                    $block = implode("\n", $lines);

                    return "alias: {\n{$block}\n{$aliasLine}\n},";
                },
                $contents
            );
            $this->line("🔹 Added alias '@{$lower}' to Vite config");
        }

        File::put($file, $contents);
    }

    protected function updateModuleServiceProvider(string $moduleStudly, string $moduleLower, string $modulePath): void
    {
        // Path to the module's AppServiceProvider
        $providerPath = $modulePath . "/src/app/Providers/AppServiceProvider.php";

        if (!File::exists($providerPath)) {
            $this->warn("⚠️ AppServiceProvider.php not found in module {$moduleStudly}");
            return;
        }

        $contents = File::get($providerPath);

        // Make sure the namespace is correct
        $contents = str_replace('Placeholder', $moduleStudly, $contents);
        $contents = str_replace('placeholder', $moduleLower, $contents);

        // Add loadRoutesFrom if not already present
        $loadRouteLine = "        \$this->loadRoutesFrom(base_path('packages/{$moduleLower}/src/routes/web.php'));";

        if (strpos($contents, $loadRouteLine) === false) {
            // Insert inside boot() method
            $contents = preg_replace(
                '/public function boot\(\)\s*\{/',
                "public function boot()\n    {\n{$loadRouteLine}",
                $contents
            );
        }

        File::put($providerPath, $contents);
        $this->line("🔹 Updated AppServiceProvider for {$moduleStudly} to load routes");
    }

    protected function updateRootAppTsx(): void
    {
        $file = base_path('resources/js/app.tsx');

        $contents = <<<'TSX'
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { initializeTheme } from './hooks/use-appearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// 1️⃣ Host app pages
const hostPages = import.meta.glob('./pages/**/*.tsx');

// 2️⃣ Automatically detect package pages
// Assumes structure: packages/<name>/src/resources/js/pages/**/*.tsx
const packagePagesGlob = import.meta.glob('../../packages/*/src/resources/js/pages/**/*.tsx');

// Map of packages
const packages: Record<string, Record<string, () => Promise<any>>> = {};

for (const path in packagePagesGlob) {
    const match = path.match(/packages\/([^/]+)\/src\/resources\/js\/pages\/(.+)\.tsx$/i);
    if (!match) continue;

    const [, pkgName, pagePath] = match;
    const normalizedPath = pagePath.replace(/\\/g, '/').toLowerCase(); // keep subfolders

    if (!packages[pkgName.toLowerCase()]) packages[pkgName.toLowerCase()] = {};
    packages[pkgName.toLowerCase()][normalizedPath] = packagePagesGlob[path];
}

// 3️⃣ Generic resolver
const resolve = async (name: string) => {
    const [pkg, ...rest] = name.split('/');
    const pagePath = rest.join('/').toLowerCase(); // will be "ecommerce/index"

    if (pkg && packages[pkg.toLowerCase()]) {
        const pages = packages[pkg.toLowerCase()];
        if (!pages[pagePath]) throw new Error(`Page not found in package "${pkg}": ${pagePath}`);
        const mod = await pages[pagePath]();
        return (mod as { default: any }).default;
    }

    // fallback to host app
    return resolvePageComponent(`./pages/${name}.tsx`, hostPages);
};

// 4️⃣ React root
let root: ReturnType<typeof createRoot> | null = null;

createInertiaApp({
    title: title => title ? `${title} - ${appName}` : appName,
    resolve,
    setup({ el, App, props }) {
        if (!root) root = createRoot(el);
        root.render(<App {...props} />);
    },
    progress: { color: '#4B5563' },
});

// 5️⃣ Theme initialization
initializeTheme();
TSX;

        File::put($file, $contents);

        $this->line("🔹 Updated root app.tsx for package page support");
    }
}

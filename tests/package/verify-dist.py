"""Verify Composer/Git exports and load runtime classes from the extracted package."""
import json
from pathlib import Path
import subprocess
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[2]


def check_archive(path):
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        forbidden = [name for name in names if name.split('/')[0] in {'vendor', 'tests', '.github'}
                     or name.split('/')[0].startswith(('.phpunit', 'phpunit.xml'))
                     or Path(name).name.startswith('.env') or Path(name).name == 'auth.json']
        if forbidden:
            raise RuntimeError(f'Development/local files found in {path.name}: {forbidden[:5]}')
        expected = ['composer.json'] + [str(p.relative_to(ROOT)) for p in (ROOT / 'src').rglob('*.php')]
        missing = set(expected) - set(names)
        if missing:
            raise RuntimeError(f'Runtime files missing: {sorted(missing)}')
        return len(names)


with tempfile.TemporaryDirectory(prefix='shipper-provider-package-') as directory:
    work = Path(directory)
    subprocess.run(['composer', 'archive', '--format=zip', '--file=provider', '--dir=' + directory], cwd=ROOT, capture_output=True, text=True, check=True)
    composer_count = check_archive(work / 'provider.zip')
    subprocess.run(['git', 'archive', '--worktree-attributes', '--format=zip', '--output=' + str(work / 'git.zip'), 'HEAD'], cwd=ROOT, check=True)
    git_count = check_archive(work / 'git.zip')
    extracted = work / 'extracted'
    with zipfile.ZipFile(work / 'provider.zip') as archive:
        archive.extractall(extracted)
    metadata = json.loads((extracted / 'composer.json').read_text())
    classes = {}
    for namespace, relative in metadata['autoload']['psr-4'].items():
        for file in (extracted / relative).rglob('*.php'):
            classes[namespace + str(file.relative_to(extracted / relative).with_suffix('')).replace('/', '\\')] = str(file)
    (work / 'classes.json').write_text(json.dumps(classes))
    php = r'''
$loader = require $argv[1].'/vendor/autoload.php';
$classes = json_decode(file_get_contents($argv[3]), true, 512, JSON_THROW_ON_ERROR);
// Override optimized checkout mappings so runtime code must come from the ZIP.
$loader->addClassMap($classes);
foreach ($classes as $class => $file) {
    $reflection = new ReflectionClass($class);
    if (realpath($reflection->getFileName()) !== realpath($file)) throw new RuntimeException('Runtime class escaped extracted package');
}
$metadata = json_decode(file_get_contents($argv[2].'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$pluginClass = $metadata['extra']['shipper-plugin'];
$plugin = new $pluginClass;
foreach ($plugin->providers() as $providerClass) {
    $reflection = new ReflectionClass($providerClass);
    if (!str_starts_with(realpath($reflection->getFileName()), realpath($argv[2]).DIRECTORY_SEPARATOR)) throw new RuntimeException('Provider was loaded outside package');
}
echo count($classes)." extracted runtime classes loaded\n";
'''
    subprocess.run(['php', '-r', php, str(ROOT), str(extracted), str(work / 'classes.json')], cwd=work, check=True)
    print(f'Composer export: {composer_count} entries; Git export: {git_count} entries; runtime files retained, development/local files excluded.')
    print('Dependencies came from the installed checkout; no fresh production dependency install or live deployment was performed.')

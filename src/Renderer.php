<?php declare(strict_types = 1);

namespace ApiGenX;

use ApiGenX\Index\FileIndex;
use ApiGenX\Index\Index;
use ApiGenX\Index\NamespaceIndex;
use ApiGenX\Info\ClassLikeInfo;
use ApiGenX\Templates\ClassicX\ClassLikeTemplate;
use ApiGenX\Templates\ClassicX\GlobalParameters;
use ApiGenX\Templates\ClassicX\NamespaceTemplate;
use ApiGenX\Templates\ClassicX\SourceTemplate;
use ApiGenX\Templates\ClassicX\TreeTemplate;
use Latte;
use Nette\Utils\FileSystem;


final class Renderer
{
	public function __construct(
		private Latte\Engine $latte,
		private UrlGenerator $urlGenerator,
	) {
	}


	public function render(Index $index, string $outputDir, int $workerCount = 1)
	{
		$templateDir = __DIR__ . '/Templates/ClassicX';
		FileSystem::delete($outputDir);
		FileSystem::createDir($outputDir);
		FileSystem::copy("$templateDir/assets", "$outputDir/assets");

		$template = new TreeTemplate(
			global: new GlobalParameters(
				index: $index,
				activePage: 'tree',
				activeNamespace: null,
				activeClassLike: null,
			),
		);

		$this->renderTemplate($template, "$outputDir/{$this->urlGenerator->tree()}");

		$this->forkLoop($workerCount, $index->namespace, function (NamespaceIndex $info) use ($outputDir, $index) {
			$template = new NamespaceTemplate(
				global: new GlobalParameters(
					index: $index,
					activePage: 'namespace',
					activeNamespace: $info,
					activeClassLike: null,
				),
				namespace: $info,
			);

			$this->renderTemplate($template, "$outputDir/{$this->urlGenerator->namespace($info)}");
		});

		$this->forkLoop($workerCount, $index->classLike, function (ClassLikeInfo $info) use ($outputDir, $index) {
			$template = new ClassLikeTemplate(
					global: new GlobalParameters(
					index: $index,
					activePage: 'namespace',
					activeNamespace: $index->namespace[$info->name->namespaceLower],
					activeClassLike: $info,
				),
				classLike: $info,
			);

			$this->renderTemplate($template, "$outputDir/{$this->urlGenerator->classLike($info)}");
		});

		$this->forkLoop($workerCount, $index->files, function (FileIndex $file, $path) use ($outputDir, $index) {
			if (!$file->primary) {
				return;
			}

			$activeClassLike = $file->classLike ? $file->classLike[array_key_first($file->classLike)] : null;
			$activeNamespace = $activeClassLike ? $index->namespace[$activeClassLike->name->namespaceLower] : null;

			$template = new SourceTemplate(
				global: new GlobalParameters(
					index: $index,
					activePage: 'source',
					activeNamespace: $activeNamespace,
					activeClassLike: $activeClassLike,
				),
				path: $path,
				source: FileSystem::read($path),
			);

			$this->renderTemplate($template, "$outputDir/{$this->urlGenerator->source($path)}");
		});
	}


	private function renderTemplate(object $template, string $outputPath): void
	{
		$classPath = (new \ReflectionClass($template))->getFileName();
		$lattePath = dirname($classPath) . '/' . basename($classPath, 'Template.php') . '.latte';
		FileSystem::write($outputPath, $this->latte->renderToString($lattePath, $template));
	}


	private function forkLoop(int $workerCount, iterable $it, callable $handle)
	{
		if (PHP_SAPI !== 'cli') {
			$workerCount = 1;
		}

		$workers = [];
		$workerId = 0;

		for ($i = 1; $i < $workerCount; $i++) {
			$pid = pcntl_fork();

			if ($pid < 0) {
				throw new \RuntimeException();

			} elseif ($pid === 0) {
				$workerId = $i;
				break;

			} else {
				$workers[] = $pid;
			}
		}

		$index = 0;
		foreach ($it as $key => $value) {
			if ((($index++) % $workerCount) === $workerId) {
				$handle($value, $key);
			}
		}

		if ($workerId !== 0) {
			exit;
		}

		foreach ($workers as $pid) {
			pcntl_waitpid($pid, $status);
		}
	}
}

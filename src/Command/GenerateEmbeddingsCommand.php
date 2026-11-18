<?php

namespace App\Command;

use Symfony\AI\Platform\Bridge\Gemini\Factory;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Indexer\DocumentIndexer;
use Symfony\AI\Store\Indexer\DocumentProcessor;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\AI\Store\Document\Transformer\TextSplitTransformer;
use Symfony\Component\Uid\Uuid;
use function sprintf;
use Symfony\AI\Store\StoreInterface;
use Symfony\AI\Store\ManagedStoreInterface;


#[AsCommand(
    name: 'app:generate-embeddings',
    description: 'Generate embeddings for a given file',
)]
class GenerateEmbeddingsCommand
{
    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')]
        private readonly ?string $apiKey,
        private readonly StoreInterface $store,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Path to the file to vectorize')] string $file,
    ): int {
        $io->note(sprintf('The value of $file is: %s', var_export($file, true)));

        if (!is_file($file)) {
            $io->error(sprintf('The file %s is not a file.', $file));
            return Command::FAILURE;
        }

        if (!is_readable($file)) {
            $io->error(sprintf('The file %s is not readable.', $file));
            return Command::FAILURE;
        }

        if (empty($this->apiKey)) {
            $io->error('GEMINI_API_KEY is not configured in .env / .env.local.');
            return Command::FAILURE;
        }

        $content = file_get_contents($file);
        if (false === $content || '' === trim($content)) {
            $io->error(sprintf('The file %s is empty or could not be read.', $file));
            return Command::FAILURE;
        }
      
        if ($this->store instanceof ManagedStoreInterface) {
            try {
                $this->store->setup();
            } catch (\Throwable) {
                // La collection existe déjà dans ChromaDB
            }
        }

        // 1. Création du document Symfony AI avec ses métadonnées
        $document = new TextDocument(
            id: Uuid::v5(Uuid::fromString(Uuid::NAMESPACE_URL), basename($file)),
            content: $content,
            metadata: new Metadata(['source' => basename($file)]),
        );


        $platform = Factory::createPlatform($this->apiKey);
        $vectorizer = new Vectorizer($platform, 'gemini-embedding-001');
        $splitter = new TextSplitTransformer(chunkSize: 500, overlap: 200);

       $indexer = new DocumentIndexer(
            new DocumentProcessor($vectorizer, $this->store, [], [$splitter])
        );
        $indexer->index($document);

        $io->success(sprintf('Embeddings successfully generated for "%s"!', basename($file)));

        return Command::SUCCESS;
    }
}

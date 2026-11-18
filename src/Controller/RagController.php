<?php

namespace App\Controller;

use App\Form\AskQuestionType;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\Vectorizer;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RagController extends AbstractController
{
    public function __construct(
        private readonly PlatformInterface $gemini,
        private readonly StoreInterface $chromadbDefault,
        private readonly AgentInterface $rag
    ) {}

    #[Route('/', name: 'app_rag', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(AskQuestionType::class);
        $form->handleRequest($request);

        $response = null;
        $sources = [];

     if ($form->isSubmitted() && $form->isValid()) {
            set_time_limit(120);
            $data = $form->getData();
            $prompt = $data['prompt'];

            try {
                // 1. Vectorisation de la question
                $vectorizer = new Vectorizer($this->gemini, 'gemini-embedding-001');
                $vector = $vectorizer->vectorize($prompt);

                // 2. Recherche de similarité dans ChromaDB
                $chunks = $this->chromadbDefault->query(new VectorQuery($vector));

                // 3. Extraction du texte et des sources depuis les métadonnées
                $contexts = [];
                foreach ($chunks as $chunk) {
                    
                    $metadata = $chunk->getMetadata();
                    $text = $metadata->getText() ?? $metadata['text'] ?? $metadata['content'] ?? '';

                    if ($text !== '') {
                        $contexts[] = $text;
                    }
                    if (isset($metadata['source'])) {
                        $sources[] = $metadata['source'];
                    }
                }

                // 4. Construction du prompt augmenté et appel à l'agent Gemini
                $contextBlock = implode("\n---\n", $contexts);
                $fullPrompt = "Contexte :\n{$contextBlock}\n\nQuestion :\n{$prompt}";

                $response = $this->rag->call($fullPrompt)->getContent();
                $sources = array_unique($sources);
            } catch (\Throwable $e) {
                $response = 'Erreur lors du traitement de la demande : ' . $e->getMessage();
            }
        }

        return $this->render('rag/index.html.twig', [
            'form' => $form,
            'response' => $response,
            'sources' => $sources,
        ]);
    }
}
<?php
declare(strict_types=1);

function deliverGeneratedDocumentEmail(PDO $pdo, array $document, callable $send): array
{
    $documentId = (int) ($document['id'] ?? 0);
    if ($documentId < 1) {
        throw new InvalidArgumentException('A generated document is required for email delivery.');
    }

    $claim = $pdo->prepare(
        'UPDATE generated_documents
         SET email_sending_at = NOW()
         WHERE id = :id
           AND email_sent_at IS NULL
           AND (email_sending_at IS NULL OR email_sending_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))'
    );
    $claim->execute(['id' => $documentId]);

    if ($claim->rowCount() !== 1) {
        $status = $pdo->prepare('SELECT email_sent_at FROM generated_documents WHERE id = :id LIMIT 1');
        $status->execute(['id' => $documentId]);
        return ['sent' => $status->fetchColumn() !== false, 'already_sent' => true];
    }

    try {
        $send();
        $complete = $pdo->prepare(
            'UPDATE generated_documents
             SET email_sent_at = NOW(), email_sending_at = NULL
             WHERE id = :id'
        );
        $complete->execute(['id' => $documentId]);
        return ['sent' => true, 'already_sent' => false];
    } catch (Throwable $exception) {
        $release = $pdo->prepare(
            'UPDATE generated_documents
             SET email_sending_at = NULL
             WHERE id = :id AND email_sent_at IS NULL'
        );
        $release->execute(['id' => $documentId]);
        return ['sent' => false, 'already_sent' => false];
    }
}


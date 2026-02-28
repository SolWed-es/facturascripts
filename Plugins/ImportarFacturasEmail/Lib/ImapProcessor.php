<?php
/**
 * ImportarFacturasEmail - Procesador IMAP para descargar emails con PDFs
 * Copyright (C) 2026 SOLWED <admin@solwed.es>
 */

namespace FacturaScripts\Plugins\ImportarFacturasEmail\Lib;

use FacturaScripts\Core\Tools;

class ImapProcessor
{
    private $connection;
    private string $host;
    private int $port;
    private string $user;
    private string $password;
    private string $folder;
    private bool $ssl;

    public function __construct(
        string $host,
        int $port,
        string $user,
        string $password,
        string $folder = 'INBOX',
        bool $ssl = true
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->folder = $folder;
        $this->ssl = $ssl;
    }

    public function connect(): bool
    {
        $mailbox = $this->buildMailboxString();

        $this->connection = @imap_open(
            $mailbox,
            $this->user,
            $this->password,
            0,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
        );

        if ($this->connection === false) {
            $error = imap_last_error();
            Tools::log('ImportarFacturasEmail')->error('IMAP connect error: ' . $error);
            return false;
        }

        return true;
    }

    public function disconnect(): void
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    public function testConnection(): bool
    {
        if ($this->connect()) {
            $this->disconnect();
            return true;
        }
        return false;
    }

    public function getUnreadCount(): int
    {
        if (!$this->connection && !$this->connect()) {
            return 0;
        }

        $info = imap_status($this->connection, $this->buildMailboxString(), SA_UNSEEN);
        return $info ? $info->unseen : 0;
    }

    /**
     * Obtiene emails no leidos con PDFs adjuntos
     * @return array Array de emails con estructura: [uid, from, subject, date, attachments]
     */
    public function getUnreadEmailsWithPdf(): array
    {
        $emails = [];

        if (!$this->connection && !$this->connect()) {
            return $emails;
        }

        // Buscar emails no leidos
        $uids = imap_search($this->connection, 'UNSEEN', SE_UID);

        if ($uids === false) {
            return $emails;
        }

        foreach ($uids as $uid) {
            $emailData = $this->getEmailData($uid);

            if ($emailData && !empty($emailData['pdf_attachments'])) {
                $emails[] = $emailData;
            }
        }

        return $emails;
    }

    /**
     * Obtiene los datos de un email por UID
     */
    public function getEmailData(int $uid): ?array
    {
        if (!$this->connection) {
            return null;
        }

        $overview = imap_fetch_overview($this->connection, (string)$uid, FT_UID);
        if (empty($overview)) {
            return null;
        }

        $header = $overview[0];
        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);

        $pdfAttachments = $this->findPdfAttachments($uid, $structure);

        return [
            'uid' => $uid,
            'from' => $this->decodeHeader($header->from ?? ''),
            'subject' => $this->decodeHeader($header->subject ?? ''),
            'date' => $header->date ?? '',
            'pdf_attachments' => $pdfAttachments
        ];
    }

    /**
     * Busca adjuntos PDF en la estructura del email
     */
    private function findPdfAttachments(int $uid, $structure, string $partNum = ''): array
    {
        $attachments = [];

        if (!$structure) {
            return $attachments;
        }

        // Si tiene partes (multipart)
        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $thisPartNum = $partNum ? $partNum . '.' . ($index + 1) : (string)($index + 1);
                $attachments = array_merge(
                    $attachments,
                    $this->findPdfAttachments($uid, $part, $thisPartNum)
                );
            }
        } else {
            // Verificar si es PDF
            if ($this->isPdfAttachment($structure)) {
                $filename = $this->getAttachmentFilename($structure);
                if ($filename) {
                    $attachments[] = [
                        'filename' => $filename,
                        'part' => $partNum ?: '1',
                        'encoding' => $structure->encoding ?? 0
                    ];
                }
            }
        }

        return $attachments;
    }

    /**
     * Verifica si una parte es un PDF
     */
    private function isPdfAttachment($part): bool
    {
        // Tipo 3 = application
        if (!isset($part->type) || $part->type !== 3) {
            return false;
        }

        // Subtipo pdf
        $subtype = strtolower($part->subtype ?? '');
        if ($subtype === 'pdf') {
            return true;
        }

        // Verificar por nombre de archivo
        $filename = $this->getAttachmentFilename($part);
        if ($filename && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf') {
            return true;
        }

        return false;
    }

    /**
     * Obtiene el nombre del archivo adjunto
     */
    private function getAttachmentFilename($part): ?string
    {
        $filename = null;

        // Buscar en parameters
        if (isset($part->parameters) && is_array($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    $filename = $this->decodeHeader($param->value);
                    break;
                }
            }
        }

        // Buscar en dparameters
        if (!$filename && isset($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    $filename = $this->decodeHeader($param->value);
                    break;
                }
            }
        }

        return $filename;
    }

    /**
     * Descarga un adjunto PDF
     */
    public function downloadAttachment(int $uid, string $partNum, int $encoding): ?string
    {
        if (!$this->connection) {
            return null;
        }

        $data = imap_fetchbody($this->connection, $uid, $partNum, FT_UID);

        if ($data === false) {
            return null;
        }

        // Decodificar segun encoding
        switch ($encoding) {
            case 0: // 7BIT
            case 1: // 8BIT
                break;
            case 2: // BINARY
                break;
            case 3: // BASE64
                $data = base64_decode($data);
                break;
            case 4: // QUOTED-PRINTABLE
                $data = quoted_printable_decode($data);
                break;
        }

        return $data;
    }

    /**
     * Marca un email como leido
     */
    public function markAsRead(int $uid): bool
    {
        if (!$this->connection) {
            return false;
        }

        return imap_setflag_full($this->connection, (string)$uid, '\\Seen', ST_UID);
    }

    /**
     * Construye la cadena de conexion al mailbox
     */
    private function buildMailboxString(): string
    {
        $flags = $this->ssl ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        return '{' . $this->host . ':' . $this->port . $flags . '}' . $this->folder;
    }

    /**
     * Decodifica cabeceras MIME
     */
    private function decodeHeader(string $text): string
    {
        $decoded = imap_mime_header_decode($text);
        $result = '';

        foreach ($decoded as $part) {
            $charset = $part->charset === 'default' ? 'UTF-8' : $part->charset;
            if (strtoupper($charset) !== 'UTF-8') {
                $converted = @iconv($charset, 'UTF-8//IGNORE', $part->text);
                $result .= $converted !== false ? $converted : $part->text;
            } else {
                $result .= $part->text;
            }
        }

        return trim($result);
    }
}

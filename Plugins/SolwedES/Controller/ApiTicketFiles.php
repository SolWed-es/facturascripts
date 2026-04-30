<?php
/**
 * Plugin SolwedES - API Controller for Ticket File Attachments
 * Handles file uploads and downloads for support tickets
 *
 * Endpoint: /api/3/ticket-files
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\PortalTicketFile;

class ApiTicketFiles extends ApiController
{
    /** Allowed file extensions */
    private const ALLOWED_EXTENSIONS = ['avi', 'csv', 'gif', 'jpg', 'jpeg', 'pdf', 'mp4', 'png', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    /** Max file size in bytes (10MB) */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    protected function runResource(): void
    {
        $method = $this->request->getMethod();

        switch ($method) {
            case 'POST':
                $this->uploadFile();
                break;
            case 'GET':
                $this->getFiles();
                break;
            case 'DELETE':
                $this->deleteFile();
                break;
            default:
                $this->response->setStatusCode(405);
                $this->response->setContent(json_encode(['error' => 'Method not allowed']));
        }
    }

    /**
     * Upload a file attachment for a ticket or comment
     */
    private function uploadFile(): void
    {
        $idTicket = (int) $this->request->request->get('id_ticket', 0);
        $idComment = (int) $this->request->request->get('id_ticket_comment', 0);

        if ($idTicket <= 0) {
            $this->response->setStatusCode(400);
            $this->response->setContent(json_encode(['error' => 'id_ticket is required']));
            return;
        }

        $uploadedFile = $this->request->files->get('file');
        if (null === $uploadedFile || !$uploadedFile->isValid()) {
            $this->response->setStatusCode(400);
            $this->response->setContent(json_encode(['error' => 'No valid file uploaded']));
            return;
        }

        // Check file size
        if ($uploadedFile->getSize() > self::MAX_FILE_SIZE) {
            $this->response->setStatusCode(400);
            $this->response->setContent(json_encode(['error' => 'File too large (max 10MB)']));
            return;
        }

        // Validate extension
        $originalName = $uploadedFile->getClientOriginalName();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            $this->response->setStatusCode(400);
            $this->response->setContent(json_encode([
                'error' => 'File type not allowed',
                'allowed' => implode(', ', self::ALLOWED_EXTENSIONS),
            ]));
            return;
        }

        // Create directory
        $folderPath = 'MyFiles' . DIRECTORY_SEPARATOR . 'PortalTicket' . DIRECTORY_SEPARATOR . $idTicket;
        $folderFullPath = FS_FOLDER . DIRECTORY_SEPARATOR . $folderPath;
        if (!Tools::folderCheckOrCreate($folderFullPath)) {
            $this->response->setStatusCode(500);
            $this->response->setContent(json_encode(['error' => 'Could not create storage directory']));
            return;
        }

        // Generate unique filename
        $safeFileName = time() . '_' . preg_replace('/[^a-z0-9\.]/i', '-', strtolower($originalName));
        $filePath = $folderPath . DIRECTORY_SEPARATOR . $safeFileName;
        $fullPath = FS_FOLDER . DIRECTORY_SEPARATOR . $filePath;

        // Move file
        try {
            $uploadedFile->move($folderFullPath, $safeFileName);
        } catch (\Exception $e) {
            $this->response->setStatusCode(500);
            $this->response->setContent(json_encode(['error' => 'Could not save file']));
            return;
        }

        // Create database record
        $ticketFile = new PortalTicketFile();
        $ticketFile->id_ticket = $idTicket;
        if ($idComment > 0) {
            $ticketFile->id_ticket_comment = $idComment;
        }
        $ticketFile->file_name = $safeFileName;
        $ticketFile->file_path = $filePath;

        if (!$ticketFile->save()) {
            // Cleanup file on DB error
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            $this->response->setStatusCode(500);
            $this->response->setContent(json_encode(['error' => 'Could not save file record']));
            return;
        }

        $this->response->setContent(json_encode([
            'id' => $ticketFile->id,
            'id_ticket' => $ticketFile->id_ticket,
            'id_ticket_comment' => $ticketFile->id_ticket_comment,
            'file_name' => $originalName,
            'stored_name' => $safeFileName,
        ]));
    }

    /**
     * Get files for a ticket or comment
     */
    private function getFiles(): void
    {
        $idTicket = (int) $this->request->get('id_ticket', 0);
        $idComment = (int) $this->request->get('id_ticket_comment', 0);
        $fileId = (int) $this->request->get('id', 0);

        // Download a specific file
        if ($fileId > 0) {
            $this->downloadFile($fileId);
            return;
        }

        if ($idTicket <= 0) {
            $this->response->setStatusCode(400);
            $this->response->setContent(json_encode(['error' => 'id_ticket is required']));
            return;
        }

        $where = [Where::column('id_ticket', $idTicket)];
        if ($idComment > 0) {
            $where[] = Where::column('id_ticket_comment', $idComment);
        }

        $files = PortalTicketFile::all($where, ['id' => 'ASC']);
        $result = [];

        foreach ($files as $file) {
            $result[] = [
                'id' => $file->id,
                'id_ticket' => $file->id_ticket,
                'id_ticket_comment' => $file->id_ticket_comment,
                'file_name' => $file->file_name,
            ];
        }

        $this->response->setContent(json_encode($result));
    }

    /**
     * Download a specific file by ID
     */
    private function downloadFile(int $fileId): void
    {
        $ticketFile = new PortalTicketFile();
        if (!$ticketFile->loadFromCode($fileId)) {
            $this->response->setStatusCode(404);
            $this->response->setContent(json_encode(['error' => 'File not found']));
            return;
        }

        $fullPath = FS_FOLDER . DIRECTORY_SEPARATOR . $ticketFile->file_path;
        if (!file_exists($fullPath)) {
            $this->response->setStatusCode(404);
            $this->response->setContent(json_encode(['error' => 'File not found on disk']));
            return;
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        $this->response->headers->set('Content-Type', $mimeType);
        $this->response->headers->set('Content-Disposition', 'inline; filename="' . $ticketFile->file_name . '"');
        $this->response->setContent(file_get_contents($fullPath));
    }

    /**
     * Delete a file by ID
     */
    private function deleteFile(): void
    {
        $fileId = (int) $this->request->request->get('id', 0);
        if ($fileId <= 0) {
            $fileId = (int) $this->request->get('id', 0);
        }

        if ($fileId <= 0) {
            $this->response->setStatusCode(400);
            $this->response->setContent(json_encode(['error' => 'id is required']));
            return;
        }

        $ticketFile = new PortalTicketFile();
        if (!$ticketFile->loadFromCode($fileId)) {
            $this->response->setStatusCode(404);
            $this->response->setContent(json_encode(['error' => 'File not found']));
            return;
        }

        if (!$ticketFile->delete()) {
            $this->response->setStatusCode(500);
            $this->response->setContent(json_encode(['error' => 'Could not delete file']));
            return;
        }

        $this->response->setContent(json_encode(['success' => true]));
    }
}

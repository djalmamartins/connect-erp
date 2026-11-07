<?php

namespace Source\Support;

use PDO;
use PDOException;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Source\Core\Connect;

/**
 * ERP | Class Email
 *
 * @author Djalma Martins
 * @package Source\Core
 */
class Email
{
    /** @var array */
    private $data;

    /** @var PHPMailer */
    private $mail;

    /** @var Message */
    private $message;

    /**
     * Email constructor.
     */
    public function __construct()
    {
        $this->mail = new PHPMailer(true);
        $this->data = new \stdClass();
        $this->message = new Message();

        //setup
        $this->mail->isSMTP();
        $this->mail->setLanguage(CONF_MAIL_OPTION_LANG);
        $this->mail->isHTML(CONF_MAIL_OPTION_HTML);
        $this->mail->SMTPAuth = CONF_MAIL_OPTION_AUTH;
        $this->mail->SMTPSecure = CONF_MAIL_OPTION_SECURE;
        $this->mail->CharSet = CONF_MAIL_OPTION_CHARSET;

        //auth
        $this->mail->Host = CONF_MAIL_HOST;
        $this->mail->Port = CONF_MAIL_PORT;
        $this->mail->Username = CONF_MAIL_USER;
        $this->mail->Password = CONF_MAIL_PASS;
    }

    /**
     * @param string $subject
     * @param string $body
     * @param string $recipient
     * @param string $recipientName
     * @return Email
     */
    public function bootstrap(string $subject, string $body, string $recipient, string $recipientName): Email
    {
        $this->data->subject = $subject;
        $this->data->body = $body;
        $this->data->recipient_email = $recipient;
        $this->data->recipient_name = $recipientName;
        return $this;
    }

    /**
     * @param string $filePath
     * @param string $fileName
     * @return Email
     */
    public function attach(string $filePath, string $fileName): Email
    {
        $this->data->attach[$filePath] = $fileName;
        return $this;
    }

    /**
     * @param $from
     * @param $fromName
     * @return bool
     */
    public function send(string $from = CONF_MAIL_SENDER['address'], string $fromName = CONF_MAIL_SENDER["name"]): bool
    {
        if (empty($this->data)) {
            $this->message->error("Erro ao enviar, favor verifique os dados");
            return false;
        }

        if (!is_email($this->data->recipient_email)) {
            $this->message->warning("O e-mail de destinatário não é válido");
            return false;
        }

        if (!is_email($from)) {
            $this->message->warning("O e-mail de remetente não é válido");
            return false;
        }

        try {
            $this->mail->Subject = $this->data->subject;
            $this->mail->msgHTML($this->data->body);
            $this->mail->addAddress($this->data->recipient_email, $this->data->recipient_name);
            $this->mail->setFrom($from, $fromName);

            if (!empty($this->data->attach)) {
                foreach ($this->data->attach as $path => $name) {
                    $this->mail->addAttachment($path, $name);
                }
            }

            $this->mail->send();
            $this->mail->clearAddresses();
            $this->mail->clearAttachments();
            return true;
        } catch (Exception $exception) {
            AppLogger::exception($exception, 'mail', ['event_type' => 'mail_send_failed', 'recipient_hash' => hash('sha256', (string)$this->data->recipient_email)]);
            $this->message->error($exception->getMessage());
            return false;
        }
    }

    /**
     * @param string $from
     * @param string $fromName
     * @return bool
     */
    public function queue(
        string $from = CONF_MAIL_SENDER['address'],
        string $fromName = CONF_MAIL_SENDER["name"],
        ?string $scheduledAt = null,
        ?int $messageId = null,
        ?int $userId = null,
        ?int $proposalResponseId = null
    ): bool
    {
        try {
            $stmt = Connect::getInstance()->prepare(
                "INSERT INTO
                    mail_queue (notification_message_id, proposal_response_id, users_id, subject, body, attachments_json, from_email, from_name, recipient_email, recipient_name, status, scheduled_at)
                    VALUES (:message_id, :proposal_response_id, :user_id, :subject, :body, :attachments, :from_email, :from_name, :recipient_email, :recipient_name, 'pending', :scheduled_at)"
            );

            $stmt->bindValue(":subject", $this->data->subject, \PDO::PARAM_STR);
            $stmt->bindValue(":body", $this->data->body, \PDO::PARAM_STR);
            $stmt->bindValue(":attachments", !empty($this->data->attach) ? json_encode($this->data->attach, JSON_UNESCAPED_SLASHES) : null, !empty($this->data->attach) ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
            $stmt->bindValue(":from_email", $from, \PDO::PARAM_STR);
            $stmt->bindValue(":from_name", $fromName, \PDO::PARAM_STR);
            $stmt->bindValue(":recipient_email", $this->data->recipient_email, \PDO::PARAM_STR);
            $stmt->bindValue(":recipient_name", $this->data->recipient_name, \PDO::PARAM_STR);
            $stmt->bindValue(":message_id", $messageId, $messageId ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
            $stmt->bindValue(":proposal_response_id", $proposalResponseId, $proposalResponseId ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
            $stmt->bindValue(":user_id", $userId, $userId ? \PDO::PARAM_INT : \PDO::PARAM_NULL);
            $stmt->bindValue(":scheduled_at", $scheduledAt ?: date("Y-m-d H:i:s"), \PDO::PARAM_STR);

            $stmt->execute();
            return true;
        } catch (PDOException $exception) {
            AppLogger::exception($exception, 'mail', ['event_type' => 'mail_queue_failed', 'recipient_hash' => hash('sha256', (string)$this->data->recipient_email)]);
            $this->message->error($exception->getMessage());
            return false;
        }
    }

    /**
     * @param int $perSecond
     */
    public function sendQueue(int $perSecond = 5, int $limit = 50): array
    {
        $pdo = Connect::getInstance();
        $limit = max(1, min(200, $limit));
        $perSecond = max(1, min(20, $perSecond));
        $items = $pdo->query("SELECT * FROM mail_queue WHERE status IN ('pending','retry') AND sent_at IS NULL AND cancelled_at IS NULL AND COALESCE(scheduled_at, created_at) <= NOW() AND (next_attempt_at IS NULL OR next_attempt_at <= NOW()) ORDER BY COALESCE(scheduled_at, created_at), id LIMIT {$limit}")->fetchAll();
        $result = ["processed" => 0, "sent" => 0, "failed" => 0, "retry" => 0];
        foreach ($items as $send) {
            $claim = $pdo->prepare("UPDATE mail_queue SET status = 'processing', last_attempt_at = NOW(), attempts = attempts + 1 WHERE id = :id AND status IN ('pending','retry')");
            $claim->execute(["id" => $send->id]);
            if (!$claim->rowCount()) {
                continue;
            }
            $result["processed"]++;
            $email = $this->bootstrap($send->subject, $send->body, $send->recipient_email, $send->recipient_name);
            $attachments = json_decode((string)($send->attachments_json ?? ''), true);
            foreach (is_array($attachments) ? $attachments : [] as $path => $name) {
                if (is_string($path) && is_file($path)) $email->attach($path, is_string($name) ? $name : basename($path));
            }
            if ($email->send($send->from_email, $send->from_name)) {
                $done = $pdo->prepare("UPDATE mail_queue SET status = 'sent', sent_at = NOW(), error_message = NULL, next_attempt_at = NULL WHERE id = :id");
                $done->execute(["id" => $send->id]);
                if (!empty($send->proposal_response_id)) {
                    $pdo->prepare("UPDATE proposal_responses SET status='sent',sent_at=NOW() WHERE id=:id")->execute(["id" => $send->proposal_response_id]);
                }
                $result["sent"]++;
            } else {
                $attempt = ((int)$send->attempts) + 1;
                $isFailed = $attempt >= (int)$send->max_attempts;
                $delayMinutes = min(240, 5 * (2 ** max(0, $attempt - 1)));
                $failure = $pdo->prepare("UPDATE mail_queue SET status = :status, error_message = :error, failed_at = :failed, next_attempt_at = :next WHERE id = :id");
                $failure->execute([
                    "status" => $isFailed ? "failed" : "retry",
                    "error" => mb_substr(strip_tags($this->message->getText() ?: "Falha no envio SMTP"), 0, 500),
                    "failed" => $isFailed ? date("Y-m-d H:i:s") : null,
                    "next" => $isFailed ? null : date("Y-m-d H:i:s", strtotime("+{$delayMinutes} minutes")),
                    "id" => $send->id
                ]);
                if ($isFailed && !empty($send->proposal_response_id)) {
                    $pdo->prepare("UPDATE proposal_responses SET status='failed' WHERE id=:id")->execute(["id" => $send->proposal_response_id]);
                }
                $result[$isFailed ? "failed" : "retry"]++;
            }
            usleep((int)(1000000 / $perSecond));
        }
        return $result;
    }

    /**
     * @return PHPMailer
     */
    public function mail(): PHPMailer
    {
        return $this->mail;
    }

    /**
     * @return Message
     */
    public function message(): Message
    {
        return $this->message;
    }
}

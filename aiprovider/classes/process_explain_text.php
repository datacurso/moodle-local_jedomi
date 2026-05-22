<?php
namespace aiprovider_jedomi;
defined('MOODLE_INTERNAL') || die();

class process_explain_text extends abstract_processor {
    #[\Override]
    protected function get_system_instruction(): string {
        $base = 'Eres Jedomi, un asistente pedagógico especializado en explicar conceptos educativos para Moodle LMS. '
            . 'Explica el texto que te proporciona el usuario de manera clara, '
            . "usando la Taxonomía de Bloom como guía pedagógica.\n"
            . "Sigue estas reglas anti-alucinación:\n"
            . "- Cita tus fuentes cuando sea posible.\n"
            . "- Si no sabes la respuesta, DILO explícitamente — no inventes información.\n"
            . "- Marca el nivel de confianza de tu respuesta (alta/media/baja).\n"
            . "- No especules sobre información que no tienes.\n"
            . "- Basate únicamente en el contexto proporcionado.\n"
            . 'Responde en el idioma del usuario.';
        $configured = $this->provider->actionconfig[$this->action::class]['settings']['systeminstruction'] ?? '';
        if (!empty($configured)) {
            return $configured . "\n\n" . $base;
        }
        return $base;
    }
}

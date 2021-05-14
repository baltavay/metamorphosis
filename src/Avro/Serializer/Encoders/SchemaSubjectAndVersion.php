<?php
namespace Metamorphosis\Avro\Serializer\Encoders;

use AvroIOBinaryEncoder;
use AvroIODatumWriter;
use AvroSchema;
use AvroStringIO;
use Metamorphosis\Avro\CachedSchemaRegistryClient;
use Metamorphosis\Avro\Schema;
use Metamorphosis\Avro\Serializer\SchemaFormats;
use RuntimeException;

class SchemaSubjectAndVersion implements EncoderInterface
{
    /**
     * @var CachedSchemaRegistryClient
     */
    private $registry;

    public function __construct(CachedSchemaRegistryClient $registry)
    {
        $this->registry = $registry;
    }

    public function encode(Schema $schema, $message, ?string $subject = null): string
    {
        $version = $this->registry->getSchemaVersion($subject, $schema);

        $writer = new AvroIODatumWriter($schema->getAvroSchema());
        $io = new AvroStringIO();

        // write the header

        // magic byte
        $io->write(pack('C', SchemaFormats::MAGIC_BYTE_SUBJECT_VERSION));

        // write the subject length in network byte order (big end)
        $io->write(pack('N', strlen($subject)));

        // then the subject
        foreach (str_split($subject) as $letter) {
            $io->write(pack('C', ord($letter)));
        }

        // and finally the version
        $io->write(pack('N', $version));

        // write the record to the rest of it
        // Create an encoder that we'll write to
        $encoder = new AvroIOBinaryEncoder($io);

        // write the object in 'obj' as Avro to the fake file...
        $writer->write($message, $encoder);

        return $io->string();
    }
}

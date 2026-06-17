<?php 

namespace Fedale\GridviewBundle\Serializer;

use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;


class ModelNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    
    // public function __construct(private ObjectNormalizer $normalizer)
    // {}
    
    public function normalize(mixed $object, ?string $format = null, array $context = [])
    {
        $data = $this->normalizer->normalize($object, $format, $context);
    }
    public function supportsNormalization(mixed $data, ?string $format = null): bool
    {
        return false;
    }

    public function setNormalizer(NormalizerInterface $normalizer)
    {
        $this->normalizer = $normalizer;
    }
}
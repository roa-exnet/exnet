<?php

namespace App\ModuloStreaming\Form;

use App\ModuloStreaming\Entity\Video;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class VideoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titulo', TextType::class, [
                'label' => 'Título *',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'El título es obligatorio.']),
                ],
                'attr' => ['class' => 'form-control'],
            ])
            ->add('descripcion', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('tipo', ChoiceType::class, [
                'label' => 'Tipo *',
                'choices' => [
                    'Película' => 'pelicula',
                    'Serie' => 'serie',
                ],
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'El tipo es obligatorio.']),
                ],
                'attr' => ['class' => 'form-select', 'onchange' => 'toggleEpisodeFields()'],
            ])
            ->add('imagen', UrlType::class, [
                'label' => 'URL de la Imagen',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'https://example.com/imagen.jpg'],
            ])
            ->add('anio', IntegerType::class, [
                'label' => 'Año',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1900, 'max' => date('Y')],
            ])
            ->add('esPublico', CheckboxType::class, [
                'label' => 'Público (visible para todos los usuarios)',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('temporada', IntegerType::class, [
                'label' => 'Temporada',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1],
            ])
            ->add('episodio', IntegerType::class, [
                'label' => 'Episodio',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1],
            ])
            ->add('videoFile', FileType::class, [
                'label' => 'Archivo de Video',
                'required' => true,
                'mapped' => false, // This field is not directly mapped to the entity
                'constraints' => [
                    new NotBlank(['message' => 'El archivo de video es obligatorio.']),
                ],
                'attr' => ['class' => 'form-control', 'accept' => 'video/mp4'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}
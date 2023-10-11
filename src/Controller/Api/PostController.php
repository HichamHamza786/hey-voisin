<?php

namespace App\Controller\Api;

use App\Entity\City;
use App\Entity\Post;
use App\Entity\User;
use App\Entity\Category;
use App\Repository\PostRepository;
use Symfony\Component\Mailer\Mailer;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

#[Route('/api')]
class PostController extends AbstractController
{
    #[Route('/post', name: 'app_api_post', methods: ['GET'])]
    public function index(PostRepository $post): JsonResponse
    {
        $posts = $post->findAll();

        return $this->json($posts, Response::HTTP_OK, [], ['groups' => 'posts']);
    }

    #[Route('/post/{id}', name: 'app_api_post_show', methods: ['GET'])]
    public function show(PostRepository $post, int $id): JsonResponse
    {
        $post = $post->find($id);

        return $this->json($post, Response::HTTP_OK, [], ['groups' => 'posts']);
    }

    #[Route('/post', name: 'app_api_post_new', methods: ['POST'])]
    public function new(EntityManagerInterface $manager, Request $request, SerializerInterface $serializer, ValidatorInterface $validator): JsonResponse
    {

        $data = json_decode($request->getContent(), true);

        try {
            $post = $serializer->deserialize($request->getContent(), Post::class, 'json', [
                AbstractNormalizer::IGNORED_ATTRIBUTES => ['category', 'city', 'user']
            ]);
        } catch (NotEncodableValueException) {
            return $this->json(['error' => "Il semble y avoir un problème !"], Response::HTTP_BAD_REQUEST);
        }

        $errors = $validator->validate($post);

        if (count($errors) > 0) {

            foreach ($errors as $error) {
                $dataErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return $this->json($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->getUser();
        \dump($user);

        if (!$user) {
            return $this->json(['error' => "Vous devez être connecté pour publier une annonce."], Response::HTTP_UNAUTHORIZED);
        }

        $categoryId = $data['category'];
        $cityId = $data['city'];

        $city = $manager->getRepository(City::class)->find($cityId);
        $category = $manager->getRepository(Category::class)->find($categoryId);
        $post->setCategory($category);
        $post->setCity($city);
        $post->setUser($user);

        $manager->persist($post);
        $manager->flush();

        return $this->json(['message' => 'Votre annonce a bien été publiée.'], Response::HTTP_CREATED, [], ['groups' => 'posts']);
    }

    #[Route('/post/city/{id}', name: 'app_api_post_city', methods: ['GET'])]
    public function showByCity(PostRepository $post, int $id): JsonResponse
    {
        $posts = $post->findBy(['city' => $id]);

        return $this->json($posts, Response::HTTP_OK, [], ['groups' => 'posts']);
    }

    #[Route('/post/category/{id}', name: 'app_api_post_category', methods: ['GET'])]
    public function showByCategory(PostRepository $post, int $id): JsonResponse
    {
        $posts = $post->findBy(['category' => $id]);

        return $this->json($posts, Response::HTTP_OK, [], ['groups' => 'posts']);
    }

    #[Route('/post/{id}/mailer', name: 'app_api_post_mailer', methods: ['GET , POST'])]
    public function sendEmail(EntityManagerInterface $manager, Request $request, ValidatorInterface $validator, Post $post, MailerInterface $mailer): JsonResponse
    {
        //!TODO recupération des données front
        $data = json_decode($request->getContent(), true);
        /** @var User $user */
        $user = $this->getUser();
        $senderEmail = $user->getEmail();
        $recipientEmail = $post->getUser()->getEmail();
       
        // Text, Email du destinataire ,
        //!TODO vérification des données via le validator

        $constraints = new Assert\Collection([
            'text' => [
                new Assert\Length(
                    min:5,
                    max:255,
                    minMessage: 'Your first name must be at least {{ limit }} characters long',
                    maxMessage: 'Your first name cannot be longer than {{ limit }} characters',
                )
            ],
        ]);
        // validate data
        $errors = $validator->validate($data, $constraints);
        if (count($errors) > 0) {
            return $this->json($errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        //?TODO envoi du mail
        $email = (new TemplatedEmail())
        ->from('heyvoisin.off@gmail.com')
        ->to($recipientEmail)
        ->subject('Sending emails is fun again!')

        // path of the Twig template to render
        ->htmlTemplate('email/mail.html.twig')

        // pass variables (name => value) to the template
        ->context([
        'expiration_date' => new \DateTime('+7 days'),
        'text' => $data['text'],
        'senderEmail' => $senderEmail,
        'postTitle' => $post->getTitle(),
        'senderUsername' => $user->getAlias(),

        ]);
        
        $mailer->send($email);
        return $this->json($post, Response::HTTP_OK, ['message' => 'Votre Mail as bien été envoyé !']);
    }
}
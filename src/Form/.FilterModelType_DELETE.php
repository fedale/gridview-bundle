<?php 

namespace Fedale\GridviewBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Doctrine\Common\Collections\Criteria;
use Fedale\GridviewBundle\Service\GridviewService;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FilterModelType extends AbstractType
{
    public function __construct(
        private GridviewService $gridviewService
     ) {}
    

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('save', SubmitType::class, [
            'attr' => ['class' => 'save'],
        ]);
        
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            $criteria = Criteria::create();
            $expr = Criteria::expr();
            
            foreach ($data as $key => $item) {
                if (null !== $item) {
                    
                    if ($key === 'fullname') {
                        $criteria->andWhere(
                            // $expr->andX(
                                $expr->orX(
                                    $expr->contains('p.firstname', $item),
                                    $expr->contains('p.lastname', $item),
                                    //$expr->contains('CONCAT("p.firstname", " ", "p.lastname")', $item)
                                    // Example - $qb->expr()->concat('u.firstname', $qb->expr()->concat($qb->expr()->literal(' '), 'u.lastname'))
                                )
                            // )
                        );
                    } else if ($key === 'locations') {
                        $criteria->andWhere(
                            $expr->contains('l.zipcode', $item)
                        );
                    } else {
                        $criteria->andWhere($expr->contains($key, $item));
                    }
                }
            }
            $this->gridview->getFilterModel()->setCriteria($criteria);
                
        });
    }    

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('method', 'GET');
        $resolver->setDefault('required', false);
    }


}
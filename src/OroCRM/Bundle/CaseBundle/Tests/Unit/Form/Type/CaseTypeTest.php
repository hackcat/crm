<?php

namespace OroCRM\Bundle\CaseBundle\Tests\Unit\Form\Type;

use OroCRM\Bundle\CaseBundle\Form\Type\CaseType;

class CaseTypeTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var CaseType
     */
    protected $formType;

    protected function setUp()
    {
        $this->formType = new CaseType();
    }

    /**
     * @param array $widgets
     *
     * @dataProvider formTypeProvider
     */
    public function testBuildForm(array $widgets)
    {
        $builder = $this->getMockBuilder('Symfony\Component\Form\FormBuilder')
            ->disableOriginalConstructor()
            ->getMock();

        $builder->expects($this->exactly(sizeof($widgets)))
            ->method('add')
            ->will($this->returnSelf());

        foreach ($widgets as $key => $widget) {
            $builder->expects($this->at($key))
                ->method('add')
                ->with($this->equalTo($widget))
                ->will($this->returnSelf());
        }

        $this->formType->buildForm($builder, []);
    }

    public function formTypeProvider()
    {
        return [
            'all' => [
                'widgets' => [
                    'subject',
                    'description',
                    'owner',
                    'reporter',
                    'item',
                    'origins',
                ]
            ]
        ];
    }

    public function testGetName()
    {
        $this->assertEquals('orocrm_case', $this->formType->getName());
    }

    public function testSetDefaultOptions()
    {
        $resolver = $this->getMock('Symfony\Component\OptionsResolver\OptionsResolverInterface');

        $resolver
            ->expects($this->once())
            ->method('setDefaults');

        $this->formType->setDefaultOptions($resolver);
    }
}

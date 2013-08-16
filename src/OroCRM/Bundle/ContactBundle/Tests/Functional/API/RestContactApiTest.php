<?php

namespace OroCRM\Bundle\ContactBundle\Tests\Functional\API;

use Doctrine\ORM\EntityManager;

use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\TestFrameworkBundle\Test\ToolsAPI;
use Oro\Bundle\TestFrameworkBundle\Test\Client;
use Oro\Bundle\AddressBundle\Entity\AddressType;
use OroCRM\Bundle\AccountBundle\Entity\Account;
use OroCRM\Bundle\ContactBundle\Entity\Group;
use Oro\Bundle\UserBundle\Entity\User;

/**
 * @outputBuffering enabled
 * @db_isolation
 */
class RestContactApiTest extends WebTestCase
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var array
     */
    protected $testAddress = array(
        'street' => 'contact_street',
        'city' => 'contact_city',
        'country' => 'US',
        'state' => 'US.FL',
        'postalCode' => '12345',
        'primary' => true,
        'types' => array(AddressType::TYPE_BILLING, AddressType::TYPE_SHIPPING),
    );

    public function setUp()
    {
        $this->client = static::createClient(array(), ToolsAPI::generateWsseHeader());
    }

    public function tearDown()
    {
        unset($this->client);
    }

    /**
     * @param array $actualAddresses
     */
    protected function assertAddresses(array $actualAddresses)
    {
        $this->assertCount(1, $actualAddresses);
        $address = current($actualAddresses);

        foreach (array('types', 'street', 'city') as $key) {
            $this->assertArrayHasKey($key, $address);
            $this->assertEquals($this->testAddress[$key], $address[$key]);
        }
    }

    /**
     * @return EntityManager
     */
    protected function getEntityManager()
    {
        if (!$this->entityManager) {
            $this->entityManager = $this->client->getKernel()->getContainer()->get('doctrine.orm.entity_manager');
        }

        return $this->entityManager;
    }

    /**
     * @param string $name
     * @return Account
     */
    protected function createAccount($name)
    {
        $account = new Account();
        $account->setName($name);

        $entityManager = $this->getEntityManager();
        $entityManager->persist($account);
        $entityManager->flush($account);

        return $account;
    }

    /**
     * @return Group|null
     */
    protected function getContactGroup()
    {
        $contactGroups = $this->getEntityManager()->getRepository('OroCRMContactBundle:Group')->findAll();
        if (0 == count($contactGroups)) {
            return null;
        }

        return current($contactGroups);
    }

    /**
     * @return User|null
     */
    protected function getUser()
    {
        return $this->getEntityManager()->getRepository('OroCRMContactBundle:Group')->find(1);
    }

    /**
     * @return array
     */
    public function testCreateContact()
    {
        $account = $this->createAccount('first test account');
        $contactGroup = $this->getContactGroup();
        $contactGroupIds = $contactGroup ? array($contactGroup->getId()) : array();
        $user = $this->getUser();

        $request = array(
            'contact' => array (
                'firstName'   => 'Contact_fname_' . mt_rand(),
                'lastName'    => 'Contact_lname',
                'namePrefix'  => 'Contact name prefix',
                'description' => 'Contact description',
                'source'      => 'other',
                'addresses'   => array($this->testAddress),
                'accounts'    => array($account->getId()),
                'groups'      => $contactGroupIds,
                'assignedTo'  => $user ? $user->getId() : null,
            )
        );
        $this->client->request('POST', $this->client->generate('oro_api_post_contact'), $request);
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 201);

        $contact = json_decode($result->getContent(), true);
        $this->assertArrayHasKey('id', $contact);
        $this->assertNotEmpty($contact['id']);

        return $request;
    }

    /**
     * @param $request
     * @depends testCreateContact
     * @return array
     */
    public function testGetContact($request)
    {
        $this->client->request('GET', $this->client->generate('oro_api_get_contacts'));
        $result = $this->client->getResponse();
        $entities = json_decode($result->getContent(), true);
        $this->assertNotEmpty($entities);

        $requiredContact = null;
        foreach ($entities as $entity) {
            if ($entity['firstName'] == $request['contact']['firstName']) {
                $requiredContact = $entity;
                break;
            }
        }
        $this->assertNotNull($requiredContact);

        $this->client->request(
            'GET',
            $this->client->generate('oro_api_get_contact', array('id' => $requiredContact['id']))
        );
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200);

        $selectedContact = json_decode($result->getContent(), true);
        $this->assertEquals($requiredContact, $selectedContact);

        // assert addresses
        $this->assertArrayHasKey('addresses', $selectedContact);
        $this->assertAddresses($selectedContact['addresses']);

        // assert contact groups
        $this->assertArrayHasKey('groups', $selectedContact);
        $this->assertSameSize($request['contact']['groups'], $selectedContact['groups']);
        $actualGroups = array();
        foreach ($selectedContact['groups'] as $group) {
            $this->assertArrayHasKey('id', $group);
            $actualGroups[] = $group['id'];
        }
        $this->assertEquals($request['contact']['groups'], $actualGroups);

        // assert related entities
        foreach (array('source', 'accounts', 'assignedTo') as $key) {
            $this->assertEquals($request['contact'][$key], $selectedContact[$key]);
        }

        return $selectedContact;
    }

    /**
     * @param $contact
     * @param $request
     * @depends testGetContact
     * @depends testCreateContact
     */
    public function testUpdateContact($contact, $request)
    {
        $account = $this->createAccount('second test account');
        $this->testAddress['types'] = array('billing');

        $request['contact']['firstName'] .= "_Updated";
        $request['contact']['addresses'][0]['types'] = $this->testAddress['types'];
        $request['contact']['addresses'][0]['primary'] = true;
        $request['contact']['accounts'] = array($account->getId());
        $request['contact']['reportsTo'] = $contact['id'];

        $this->client->request(
            'PUT',
            $this->client->generate('oro_api_put_contact', array('id' => $contact['id'])),
            $request
        );
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 204);

        $this->client->request('GET', $this->client->generate('oro_api_get_contact', array('id' => $contact['id'])));
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 200);

        $contact = json_decode($result->getContent(), true);
        $this->assertEquals($request['contact']['firstName'], $contact['firstName'], 'Contact was not updated');

        // assert address
        $this->assertArrayHasKey('addresses', $contact);
        $this->assertAddresses($contact['addresses']);

        // assert related entities
        foreach (array('accounts', 'reportsTo') as $key) {
            $this->assertEquals($request['contact'][$key], $contact[$key]);
        }
    }

    /**
     * @param $contact
     * @depends testGetContact
     */
    public function testDeleteContact($contact)
    {
        $this->client->request(
            'DELETE',
            $this->client->generate('oro_api_delete_contact', array('id' => $contact['id']))
        );
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 204);

        $this->client->request('GET', $this->client->generate('oro_api_get_contact', array('id' => $contact['id'])));
        $result = $this->client->getResponse();
        ToolsAPI::assertJsonResponse($result, 404);
    }
}
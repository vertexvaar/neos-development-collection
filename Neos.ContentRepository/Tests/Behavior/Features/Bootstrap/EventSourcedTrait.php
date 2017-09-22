<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Behat\Gherkin\Node\TableNode;
use Neos\ContentRepository\Domain\Projection\Content\ContentGraphInterface;
use Neos\ContentRepository\Domain\ValueObject\ContentStreamIdentifier;
use Neos\ContentRepository\Domain\ValueObject\DimensionSpacePoint;
use Neos\ContentRepository\Domain\ValueObject\DimensionSpacePointSet;
use Neos\ContentRepository\Domain\ValueObject\DimensionValues;
use Neos\ContentRepository\Domain\ValueObject\NodeIdentifier;
use Neos\EventSourcing\Event\EventInterface;
use Neos\EventSourcing\Event\EventPublisher;
use Neos\EventSourcing\Event\EventTypeResolver;
use Neos\EventSourcing\EventStore\EventAndRawEvent;
use Neos\EventSourcing\EventStore\EventStoreManager;
use Neos\EventSourcing\EventStore\StreamNameFilter;
use Neos\Flow\Property\PropertyMapper;
use Neos\Flow\Property\PropertyMappingConfiguration;
use PHPUnit\Framework\Assert;

/**
 * Features context
 */
trait EventSourcedTrait
{

    /**
     * @var EventTypeResolver
     */
    private $eventTypeResolver;


    /**
     * @var PropertyMapper
     */
    private $propertyMapper;


    /**
     * @var EventPublisher
     */
    private $eventPublisher;

    /**
     * @var EventStoreManager
     */
    private $eventStoreManager;

    private $currentEventStreamAsArray;

    /**
     * @Given /^the Event "([^"]*)" was published to stream "([^"]*)" with payload:$/
     */
    public function theEventWasPublishedToStreamWithPayload($eventType, $streamName, TableNode $payloadTable)
    {
        $eventClassName = $this->eventTypeResolver->getEventClassNameByType($eventType);
        $eventPayload = $this->readPayloadTable($payloadTable);

        /** @var EventInterface $event */
        $configuration = new PropertyMappingConfiguration();
        $configuration->allowAllProperties();
        $configuration->skipUnknownProperties();
        $configuration->forProperty('*')->allowAllProperties()->skipUnknownProperties();

        $event = $this->propertyMapper->convert($eventPayload, $eventClassName, $configuration);

        $this->eventPublisher->publish($streamName, $event);
    }

    protected function readPayloadTable(TableNode $payloadTable)
    {
        $eventPayload = [];
        foreach ($payloadTable->getHash() as $line) {
            if (!empty($line['Type'])) {
                switch ($line['Type']) {
                    case 'json':
                        $eventPayload[$line['Key']] = json_decode($line['Value'], true);
                        break;
                    case 'DimensionSpacePoint':
                        $eventPayload[$line['Key']] = new DimensionSpacePoint(json_decode($line['Value'], true));
                        break;
                    case 'DimensionSpacePointSet':
                        $tmp = json_decode($line['Value'], true);
                        $convertedPoints = [];
                        if (isset($tmp['points'])) {
                            foreach ($tmp['points'] as $point) {
                                $convertedPoints[] = new DimensionSpacePoint($point['coordinates']);
                            }
                        }
                        $eventPayload[$line['Key']] = new DimensionSpacePointSet($convertedPoints);
                        break;
                    default:
                        throw new \Exception("TODO" . json_encode($line));
                }
            } else {
                $eventPayload[$line['Key']] = $line['Value'];
            }
        }

        return $eventPayload;
    }

    /**
     * @When /^the command "([^"]*)" is executed with payload:$/
     */
    public function theCommandIsExecutedWithPayload($shortCommandName, TableNode $payloadTable)
    {
        list($commandClassName, $commandHandlerClassName, $commandHandlerMethod) = self::resolveShortCommandName($shortCommandName);
        $commandArguments = $this->readPayloadTable($payloadTable);

        /** @var EventInterface $event */
        $command = $this->propertyMapper->convert($commandArguments, $commandClassName);
        $commandHandler = $this->objectManager->get($commandHandlerClassName);

        $commandHandler->$commandHandlerMethod($command);
    }

    protected static function resolveShortCommandName($shortCommandName)
    {
        switch ($shortCommandName) {
            case 'CreateRootNode':
                return [
                    \Neos\ContentRepository\Domain\Context\Node\Command\CreateRootNode::class,
                    \Neos\ContentRepository\Domain\Context\Node\NodeCommandHandler::class,
                    'handleCreateRootNode'
                ];
            case 'CreateNodeAggregateWithNode':
                return [
                    \Neos\ContentRepository\Domain\Context\Node\Command\CreateNodeAggregateWithNode::class,
                    \Neos\ContentRepository\Domain\Context\Node\NodeCommandHandler::class,
                    'handleCreateNodeAggregateWithNode'
                ];
            case 'ForkContentStream':
                return [
                    \Neos\ContentRepository\Domain\Context\ContentStream\Command\ForkContentStream::class,
                    \Neos\ContentRepository\Domain\Context\ContentStream\ContentStreamCommandHandler::class,
                    'handleForkContentStream'
                ];
            default:
                throw new \Exception('The short command name "' . $shortCommandName . '" is currently not supported by the tests.');
        }
    }


    /**
     * @Then /^I expect exactly (\d+) events? to be published on stream "([^"]*)"$/
     */
    public function iExpectExactlyEventToBePublishedOnStream($numberOfEvents, $streamName)
    {
        $eventStore = $this->eventStoreManager->getEventStoreForStreamName($streamName);
        $stream = $eventStore->get(new StreamNameFilter($streamName));
        $this->currentEventStreamAsArray = iterator_to_array($stream);
        Assert::assertEquals($numberOfEvents, count($this->currentEventStreamAsArray), 'Number of events did not match');
    }

    /**
     * @Then /^event number (\d+) is of type "([^"]*)" with payload:/
     */
    public function eventNumberIs($eventNumber, $eventType, TableNode $payloadTable)
    {
        /* @var $actualEvent EventAndRawEvent */
        $actualEvent = $this->currentEventStreamAsArray[$eventNumber];
        Assert::assertEquals($eventType, $actualEvent->getRawEvent()->getType(), 'Event Type does not match: "' . $actualEvent->getRawEvent()->getType() . '" !== "' . $eventType . '"');

        $actualEventPayload = $actualEvent->getRawEvent()->getPayload();

        foreach ($payloadTable->getHash() as $assertionTableRow) {
            $actualValue = \Neos\Utility\Arrays::getValueByPath($actualEventPayload, $assertionTableRow['Key']);
            $expectedValue = $assertionTableRow['Expected'];
            if (isset($assertionTableRow['AssertionType']) && $assertionTableRow['AssertionType'] === 'json') {
                $expectedValue = json_decode($expectedValue, true);
            }

            Assert::assertEquals($expectedValue, $actualValue, 'ERROR at ' . $assertionTableRow['Key'] . ': ' . json_encode($actualValue) . ' !== ' . json_encode($expectedValue));
        }
    }




    /**
     * @When /^the graph projection is fully up to date$/
     */
    public function theGraphProjectionIsFullyUpToDate()
    {
        // we do not need to do anything here yet, as the graph projection is synchronous.
    }

    /**
     * @var ContentStreamIdentifier
     */
    private $contentStreamIdentifier;

    /**
     * @var DimensionSpacePoint
     */
    private $dimensionSpacePoint;

    /**
     * @var ContentGraphInterface
     */
    private $contentGraphInterface;

    /**
     * @Given /^I am in content stream "([^"]*)" and Dimension Space Point (.*)$/
     */
    public function iAmInContentStreamAndDimensionSpacePointCoordinates(string $contentStreamIdentifier, string $dimensionSpacePoint)
    {
        $this->contentStreamIdentifier = new ContentStreamIdentifier($contentStreamIdentifier);
        $this->dimensionSpacePoint = new DimensionSpacePoint(json_decode($dimensionSpacePoint, true)['coordinates']);
    }

    /**
     * @Then /^I expect a node "([^"]*)" to exist in the graph projection$/
     */
    public function iExpectANodeToExistInTheGraphProjection($nodeIdentifier)
    {
        $node = $this->contentGraphInterface->getSubgraphByIdentifier($this->contentStreamIdentifier, $this->dimensionSpacePoint)->findNodeByIdentifier(new NodeIdentifier($nodeIdentifier));
        Assert::assertNotNull($node, 'Node "' . $nodeIdentifier . '" was not found in the current Content Stream / Dimension Space Point.');
    }

    /**
     * @Then /^I expect the node "([^"]*)" to have the following child nodes:$/
     */
    public function iExpectTheNodeToHaveTheFollowingChildNodes($nodeIdentifier, TableNode $expectedChildNodesTable)
    {
        $nodes = $this->contentGraphInterface->getSubgraphByIdentifier($this->contentStreamIdentifier, $this->dimensionSpacePoint)->findChildNodes(new NodeIdentifier($nodeIdentifier));

        Assert::assertCount(count($expectedChildNodesTable->getHash()), $nodes, 'Child Node Count does not match');
        foreach ($expectedChildNodesTable->getHash() as $index => $row) {
            Assert::assertEquals($row['Name'], (string)$nodes[$index]->name, 'Node name in index ' . $index . ' does not match.');
            Assert::assertEquals($row['NodeIdentifier'], (string)$nodes[$index]->identifier, 'Node identifier in index ' . $index . ' does not match.');
        }
    }

    /**
     * @Then /^I expect the Node Aggregate "([^"]*)" to have the nodes:$/
     */
    public function iExpectTheNodeAggregateToHaveTheNodes($nodeAggregateIdentifier, TableNode $expectedNodesTable)
    {
        $nodes = $this->contentGraphInterface->getSubgraphByIdentifier($this->contentStreamIdentifier, $this->dimensionSpacePoint)->findNodesBelongingToNodeAggregate(new \Neos\ContentRepository\Domain\ValueObject\NodeAggregateIdentifier($nodeAggregateIdentifier));

        Assert::assertCount(count($expectedNodesTable->getHash()), $nodes, 'Node Aggregate Count does not match');
        foreach ($expectedNodesTable->getHash() as $index => $row) {
            Assert::assertEquals($row['NodeIdentifier'], (string)$nodes[$index]->identifier, 'Node identifier in index ' . $index . ' does not match.');
        }
    }


    /**
     * @Then /^I expect the Node "([^"]*)" to have the type "([^"]*)"$/
     */
    public function iExpectTheNodeToHaveTheType($nodeIdentifier, $nodeType)
    {
        $node = $this->contentGraphInterface->getSubgraphByIdentifier($this->contentStreamIdentifier, $this->dimensionSpacePoint)->findNodeByIdentifier(new NodeIdentifier($nodeIdentifier));
        Assert::assertEquals($nodeType, (string)$node->nodeTypeName, 'Node Type names do not match');
    }

    /**
     * @Then /^I expect the Node "([^"]*)" to have the properties:$/
     */
    public function iExpectTheNodeToHaveTheProperties($nodeIdentifier, TableNode $expectedProperties)
    {
        $node = $this->contentGraphInterface->getSubgraphByIdentifier($this->contentStreamIdentifier, $this->dimensionSpacePoint)->findNodeByIdentifier(new NodeIdentifier($nodeIdentifier));
        /* @var $properties \Neos\ContentRepository\Domain\Projection\Content\PropertyCollection */
        $properties = $node->properties;
        foreach ($expectedProperties->getHash() as $row) {
            $actualProperty = $properties[$row['Key']];
            Assert::assertEquals($row['Value'], $actualProperty, 'Node property ' . $row['Key'] . ' does not match.');
        }
    }

    /**
     * @Then /^I expect the path "([^"]*)" to lead to the node "([^"]*)"$/
     */
    public function iExpectThePathToLeadToTheNode($nodePath, $nodeIdentifier)
    {
        $node = $this->contentGraphInterface->getSubgraphByIdentifier($this->contentStreamIdentifier, $this->dimensionSpacePoint)->findNodeByPath($nodePath);
        Assert::assertNotNull($node, 'Did not find node at path "' . $nodePath . '"');
        Assert::assertEquals($nodeIdentifier, (string)$node->identifier, 'Node identifier does not match.');
    }
}

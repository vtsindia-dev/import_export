<?php
/**
 * @copyright: Copyright © 2019 Firebear Studio. All rights reserved.
 * @author : Firebear Studio <fbeardev@gmail.com>
 */
namespace Firebear\ImportExport\Model\Email;

use Magento\Framework\App\ProductMetadataInterface;
use Firebear\ImportExport\Model\Email\TransportBuilder\EmailTransportBuilder;
use Firebear\ImportExport\Model\Email\TransportBuilder\MailTransportBuilder;
use Firebear\ImportExport\Model\Email\TransportBuilder\TransportBuilder;

/**
 * Email Transport Builder Factory
 */
class TransportBuilderFactory
{
    /**
     * Transport Builder
     *
     * @var TransportBuilderInterface
     */
    protected $transportBuilder;

    /**
     * Initialize Factory
     *
     * @param ProductMetadataInterface $metadata
     * @param EmailTransportBuilder $emailTransportBuilder
     * @param MailTransportBuilder $mailTransportBuilder
     * @param TransportBuilder $transportBuilder
     */
    public function __construct(
        ProductMetadataInterface $metadata,
        EmailTransportBuilder $emailTransportBuilder,
        MailTransportBuilder $mailTransportBuilder,
        TransportBuilder $transportBuilder
    ) {
        $version = $metadata->getVersion();

        if (version_compare($version, '2.3.1', '>=')) {
            $this->transportBuilder = $emailTransportBuilder;
        } elseif (version_compare($version, '2.2.7', '<=')) {
            $this->transportBuilder = $transportBuilder;
        } else {
            $this->transportBuilder = $mailTransportBuilder;
        }
    }

    /**
     * Get Transport Builder
     *
     * @return TransportBuilderInterface
     */
    public function create()
    {
        return $this->transportBuilder;
    }
}

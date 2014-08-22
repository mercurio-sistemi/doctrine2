<?php

namespace Doctrine\Tests\Models\GeoNames;

/**
 * @Entity
 * @Table(name="geonames_country")
 * @Cache
 */
class Country
{
    /**
     * @Id
     * @Column(type="string", length=2)
     * @GeneratedValue(strategy="NONE")
     */
    protected $id;

    /**
     * @Column(type="string", length=255);
     */
    protected $name;

    public function __construct($id, $name)
    {
        $this->id = $id;
        $this->name = $name;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getName()
    {
        return $this->name;
    }
}

